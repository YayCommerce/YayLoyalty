<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The order event family. Builds a Context from the order — occurred_at
 * is the order's creation date both times — and hands it to the service;
 * no rules of its own beyond which statuses mean what. Every handler is
 * safe to re-fire: staging and release are idempotent and the award is
 * guarded by the ledger's UNIQUE idempotency_key.
 *
 * This class is the only place in the plugin that knows a WooCommerce order
 * status string. The engine, the ledger and every domain service are told
 * what to do, never which status asked for it.
 *
 * Only `completed` awards (OrderStatus::AWARD), a product decision: a store
 * that leaves orders at `processing` must complete them for them to earn.
 * Virtual orders reach it straight from payment_complete().
 */
final class OrderListener {

    private EarningService $service;

    private PendingPoints $pending;

    /** For points_earned_today and the reversed-earlier check on a duplicate. */
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
     * Attaches the two order hooks; call once at boot, after settings are readable.
     *
     * Two entry points, on purpose, both funnelled into route(). A per-status
     * hook (`woocommerce_order_status_completed`) or `woocommerce_payment_complete`
     * alongside either would describe the same transition from a different
     * angle and process it several times for no reason — that is still out.
     * `woocommerce_new_order` is not that: `woocommerce_order_status_changed`
     * only fires when WooCommerce recorded a non-empty "from" status
     * (`WC_Abstract_Order::set_status()`), so a brand-new order's very first
     * status — no prior status to transition from — never reaches it.
     * `woocommerce_new_order` fires exactly once per order regardless (at
     * first insert, or at the admin auto-draft → real-status save) and is
     * the only reliable signal for that first arrival. Where the two land in
     * the same request (that admin save fires both), route() runs twice for
     * the same $to; that is free, not a bug — see route()'s docblock.
     * Refund and trash hooks belong to OrderReversalListener.
     */
    public function register(): void {
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
        add_action( 'woocommerce_new_order', [ $this, 'on_order_created' ], 10, 2 );
    }

    /**
     * Order status transition entry point.
     *
     * Reads $to, never $order->get_status(): by the time a late-priority
     * callback runs the order may have moved again, and the transition being
     * handled is the one that was announced. $from goes only into the debug
     * line; see route() for why it plays no part in routing.
     */
    public function on_status_changed( int $order_id, string $from, string $to, \WC_Order $order ): void {
        Log::debug( 'OrderListener', 'order #' . $order_id . ' ' . $from . ' -> ' . $to );
        $this->route( $order_id, $to, $order );
    }

    /**
     * Order creation entry point — covers the arrival route() above cannot
     * see: WooCommerce never fires `woocommerce_order_status_changed` for a
     * brand-new order's first status (no prior status to transition from).
     * Routes on $order->get_status() since there is no $to being announced.
     */
    public function on_order_created( int $order_id, \WC_Order $order ): void {
        Log::debug( 'OrderListener', 'order #' . $order_id . ' created at ' . $order->get_status() );
        $this->route( $order_id, $order->get_status(), $order );
    }

    /**
     * The one routing table, shared by both entry points above.
     *
     * $from is not a parameter: first arrival wins, and any later call that
     * lands on the same $to — from either hook, including the same-request
     * double delivery register()'s docblock describes — repeats a step that
     * is already idempotent (PendingPoints::stage()/release(), invariant 2)
     * or composes the same ledger idempotency key and costs one rejected
     * insert (invariants 3-4). No dedup lives here; none is needed.
     *
     *   checkout-draft  nothing — a block-checkout draft is not an order yet
     *                   and must never reach staging
     *   pending         stage
     *   on-hold         stage, never award: the money has not arrived
     *   processing      nothing: money may have arrived, but only completed awards
     *   completed       award (OrderStatus::AWARD)
     *   failed          nothing: no release, no redemption restore. The order
     *                   is still payable and a retry lands on completed
     *   cancelled       release pending (once) and claw back everything the
     *                   order earned
     *   trash           as cancelled
     *   refunded        release pending; clawback is driven by refund objects.
     *                   One exception, handled here because only the status
     *                   says so: get_total_refunded() === 0 means an admin
     *                   reversed the order outside WooCommerce, which is a
     *                   full reversal referenced to the ORDER, not a refund id
     *
     * Any other status — one another plugin registered — is not ours to
     * interpret: it falls through and nothing happens.
     */
    private function route( int $order_id, string $to, \WC_Order $order ): void {
        if ( OrderStatus::AWARD === $to ) {
            $this->guarded( 'award', $order_id, fn() => $this->on_awardable( $order ) );
            return;
        }
        switch ( $to ) {
            case 'pending':
            case 'on-hold':
                $this->guarded( 'stage', $order_id, fn() => $this->on_stageable( $order ) );
                break;
            case 'cancelled':
            case 'trash':
                $this->guarded( 'cancel', $order_id, fn() => $this->reversals->on_cancelled( $order ) );
                break;
            case 'refunded':
                $this->guarded( 'refund', $order_id, fn() => $this->reversals->on_status_refunded( $order ) );
                break;
            case 'processing':
            case 'failed':
            case 'checkout-draft':
                // Paid but not completed, still payable, or not yet an order — none stages or awards.
                break;
        }
    }

    /**
     * Evaluation moment: preview the purchase at the order's creation date and stage its points.
     *
     * Scores the hook's own object, never a re-read: woocommerce_new_order
     * fires before WooCommerce saves the line items, so a fresh read has none.
     *
     * @throws \InvalidArgumentException When the order has no creation date (route() catches it).
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
     * Award moment: one award() per trigger the order satisfies, each noted on the order.
     *
     * @throws \InvalidArgumentException When the order has no creation date (route() catches it).
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
            // Rebuilt per trigger: the previous trigger's row counts toward today's cap.
            $today   = $this->ledger->points_earned_today( (int) $order->get_customer_id(), Dates::now(), LedgerSource::ORDER );
            $outcome = $this->service->award( (string) $trigger, ContextFactory::from_order( $order, $today ) );

            PreviewLog::write_outcome( 'OrderListener', 'order #' . $order_id . ' ' . $trigger, $outcome );
            $this->note( $order, $outcome );
        }

        // Completed ends staging whatever the triggers answered: a skipped award would otherwise leave pending forever.
        $this->pending->release( $order_id );
    }

    /**
     * Tells the merchant what an award did. Silent for a guest (every guest
     * order would say so) and for a plain duplicate (a re-fired status).
     */
    private function note( \WC_Order $order, Outcome $outcome ): void {
        $entry = $outcome->get_entry();

        switch ( $outcome->get_status() ) {
            case Outcome::AWARDED:
                $campaign = $outcome->get_evaluation() ? $outcome->get_evaluation()->get_campaign() : null;
                /* translators: 1: points, 2: campaign name */
                $order->add_order_note( sprintf( __( 'Earned %1$d points (campaign: %2$s).', 'yayloyalty' ), $entry->get_points(), $campaign ? $campaign->get_name() : '-' ) );
                break;
            case Outcome::SKIPPED:
                if ( ProgramGate::REASON_NO_CUSTOMER !== $outcome->get_reason() ) {
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

    private function guarded( string $step, int $order_id, callable $handler ): void {
        ListenerGuard::run( 'OrderListener', $step . ' failed for order #' . $order_id, $handler );
    }
}
