<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Routes order statuses to staging, award and reversal. The only class that knows WooCommerce
 * order status strings. Every handler is safe to re-fire: staging and release are idempotent,
 * and the ledger's UNIQUE idempotency_key guards the award.
 * Only `completed` awards, by product decision: a store that leaves orders at `processing`
 * must complete them for them to earn. Virtual orders get there from payment_complete().
 */
final class OrderListener {

    /** Where an order that left cancelled or the trash must land to count as restored. Not failed, refunded or another plugin's status. */
    private const LIVE_STATUSES = [ 'pending', 'on-hold', 'processing', 'completed' ];

    private const DEAD_STATUSES = [ 'cancelled', 'trash' ];

    private EarningService $service;

    private PendingPoints $pending;

    /** For the reversed-earlier check on a duplicate. */
    private LedgerRepository $ledger;

    /** Takes the statuses that reverse (cancelled, trash, refunded). */
    private OrderReversalListener $reversals;

    public function __construct( EarningService $service, PendingPoints $pending, LedgerRepository $ledger, OrderReversalListener $reversals ) {
        $this->service   = $service;
        $this->pending   = $pending;
        $this->ledger    = $ledger;
        $this->reversals = $reversals;
    }

    /**
     * Call once at boot, after settings are readable. Two hooks, because a new order's first
     * status never fires `woocommerce_order_status_changed`. `woocommerce_new_order` covers it.
     * `untrashed_post` is for an order restored from the trash: under HPOS that is a status change
     * from `trash`; under legacy post storage it fires only this hook, after WordPress restored the status.
     */
    public function register(): void {
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
        add_action( 'woocommerce_new_order', [ $this, 'on_order_created' ], 10, 2 );
        add_action( 'untrashed_post', [ $this, 'on_order_untrashed' ], 10, 1 );
    }

    /**
     * Routes on $to, never $order->get_status(): by the time a late callback runs,
     * the order may have moved again. $from also decides whether the order came back
     * from cancelled or the trash, which is handled before the route.
     */
    public function on_status_changed( int $order_id, string $from, string $to, \WC_Order $order ): void {
        Log::debug( 'OrderListener', 'order #' . $order_id . ' ' . $from . ' -> ' . $to );

        if ( in_array( $from, self::DEAD_STATUSES, true ) && in_array( $to, self::LIVE_STATUSES, true ) ) {
            ListenerGuard::for_order( 'OrderListener', 'restore', $order_id, fn() => $this->reversals->on_uncancelled( $order ) );
        }

        $this->route( $order_id, $to, $order );
    }

    /** `untrashed_post` adapter; anything that is not an order, or an order restored to a status that is not live, is ignored. */
    public function on_order_untrashed( int $order_id ): void {
        if ( ! OrderUtil::is_order( $order_id, wc_get_order_types() ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( $order instanceof \WC_Order && in_array( $order->get_status(), self::LIVE_STATUSES, true ) ) {
            ListenerGuard::for_order( 'OrderListener', 'restore', $order_id, fn() => $this->reversals->on_uncancelled( $order ) );
        }
    }

    /** Routes a new order's first status, which on_status_changed() never sees. */
    public function on_order_created( int $order_id, \WC_Order $order ): void {
        Log::debug( 'OrderListener', 'order #' . $order_id . ' created at ' . $order->get_status() );
        $this->route( $order_id, $order->get_status(), $order );
    }

    /**
     * Shared by both entry points. Nothing dedups here, because every step is idempotent.
     * on-hold stages but never awards: the money has not arrived. A status another plugin
     * registers falls through, because it is not ours to interpret.
     */
    private function route( int $order_id, string $to, \WC_Order $order ): void {
        if ( OrderStatus::AWARD === $to ) {
            ListenerGuard::for_order( 'OrderListener', 'award', $order_id, fn() => $this->on_awardable( $order ) );
            return;
        }
        switch ( $to ) {
            case 'pending':
            case 'on-hold':
                ListenerGuard::for_order( 'OrderListener', 'stage', $order_id, fn() => $this->on_stageable( $order ) );
                break;
            case 'cancelled':
            case 'trash':
                ListenerGuard::for_order( 'OrderListener', 'cancel', $order_id, fn() => $this->reversals->on_cancelled( $order ) );
                break;
            case 'refunded':
                ListenerGuard::for_order( 'OrderListener', 'refund', $order_id, fn() => $this->reversals->on_status_refunded( $order ) );
                break;
            case 'processing':
            case 'failed':
            case 'checkout-draft':
                // Paid but not completed, still payable, or not yet an order: none stages or awards.
                break;
        }
    }

    /**
     * Previews the purchase and stages its points. Scores the hook's own object, never a re-read:
     * `woocommerce_new_order` fires before WooCommerce saves the line items, so a fresh read has none.
     *
     * @throws \InvalidArgumentException When the order has no creation date (ListenerGuard::run() catches it).
     */
    public function on_stageable( \WC_Order $order ): void {
        $order_id = (int) $order->get_id();
        $result   = $this->service->preview( CampaignTrigger::PURCHASE, ContextFactory::from_order( $order ) );

        PreviewLog::write( 'OrderListener', 'order #' . $order_id . ' stage', $result );

        if ( $result->is_awarded() ) {
            $user_id = (int) $order->get_customer_id();
            $this->pending->stage( $order_id, $user_id > 0 ? $user_id : null, $result->get_points() );
        }
    }

    /**
     * Calls award() once per order trigger and notes each outcome on the order.
     *
     * @throws \InvalidArgumentException When the order has no creation date (ListenerGuard::run() catches it).
     */
    public function on_awardable( \WC_Order $order ): void {
        $order_id = (int) $order->get_id();

        /**
         * Triggers one completed order is awarded for, in order. Internal seam: Pro appends its order triggers.
         *
         * @param string[]  $triggers CampaignTrigger values.
         * @param \WC_Order $order
         */
        $triggers = (array) apply_filters( 'yayloyalty_order_award_triggers', [ CampaignTrigger::PURCHASE ], $order );

        foreach ( $triggers as $trigger ) {
            $outcome = $this->service->award( (string) $trigger, ContextFactory::from_order( $order ) );

            PreviewLog::write_outcome( 'OrderListener', 'order #' . $order_id . ' ' . $trigger, $outcome );
            $this->note( $order, $outcome );
        }

        // Completed ends staging whatever the triggers answered: a skipped award would otherwise leave pending forever.
        $this->pending->release( $order_id );
    }

    /**
     * Tells the merchant what an award did. Silent for a guest (every guest
     * order would say so), for an earning limit that refused the whole award
     * (nothing earned, nothing to report) and for a plain duplicate (a
     * re-fired status).
     */
    private function note( \WC_Order $order, Outcome $outcome ): void {
        $entry = $outcome->get_entry();

        switch ( $outcome->get_status() ) {
            case Outcome::AWARDED:
                $campaign = $outcome->get_evaluation() ? $outcome->get_evaluation()->get_campaign() : null;
                /* translators: 1: points, 2: campaign name */
                $order->add_order_note( sprintf( __( 'Earned %1$d points (campaign: %2$s).', 'yayloyalty' ), $entry->get_points(), $campaign ? $campaign->get_name() : '-' ) );

                // EarningService::AWARD_POINTS_FILTER lowered the award below what the campaign evaluated.
                $held_back = $outcome->held_back();

                if ( $held_back > 0 ) {
                    /* translators: %d: points */
                    $order->add_order_note( sprintf( __( '%d points were held back by an earning limit.', 'yayloyalty' ), $held_back ) );
                }
                break;
            case Outcome::SKIPPED:
                if ( ! in_array( $outcome->get_reason(), [ ProgramGate::REASON_NO_CUSTOMER, EarningService::REASON_LIMIT_REACHED ], true ) ) {
                    /* translators: %s: reason code */
                    $order->add_order_note( sprintf( __( 'No points earned: %s.', 'yayloyalty' ), $outcome->get_reason() ) );
                }
                break;
            case Outcome::DUPLICATE:
                if ( $this->ledger->sum_refunded_for( $entry ) > 0 ) {
                    $order->add_order_note( __( 'Points for this order were reversed earlier and are not awarded again (adjust manually if needed).', 'yayloyalty' ) );
                }
                break;
        }
    }
}
