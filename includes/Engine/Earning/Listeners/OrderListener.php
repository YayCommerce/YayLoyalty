<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;
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
 * Paid is a set, not a status. A store whose items are all downloadable and
 * virtual never sees `processing` — needs_processing() is false and
 * payment_complete() goes straight to `completed` — while many physical
 * stores never move an order past `processing`. Awarding on one status
 * breaks one of them, so the award set is a setting, defaulting to both.
 */
final class OrderListener {

    private EarningService $service;

    private PendingPoints $pending;

    /** For points_earned_today, the one ledger read the Context needs. */
    private LedgerRepository $ledger;

    public function __construct( EarningService $service, PendingPoints $pending, LedgerRepository $ledger ) {
        $this->service = $service;
        $this->pending = $pending;
        $this->ledger  = $ledger;
    }

    /**
     * Attaches the two order hooks; call once at boot, after settings are readable.
     *
     * Why two, and why no others: invariant 6 in `includes/Engine/Earning/README.md`.
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
     * double delivery of invariant 6 — repeats a step that
     * is already idempotent (PendingPoints::stage()/release(), invariant 2)
     * or composes the same ledger idempotency key and costs one rejected
     * insert (invariants 3-4). No dedup lives here; none is needed.
     *
     *   checkout-draft  nothing — a block-checkout draft is not an order yet
     *                   and must never reach staging
     *   pending         stage
     *   on-hold         stage, never award: the money has not arrived
     *   {award set}     award — `processing` + `completed` by default,
     *                   general.award_statuses in settings
     *   failed          nothing: no release, no redemption restore. The order
     *                   is still payable and a retry lands on an award status
     *   cancelled       release pending (once) and restore the redemption if
     *                   one is still applied
     *   trash           as cancelled
     *   refunded        nothing — refunds are driven by refund objects. One
     *                   exception, handled here because only the status says
     *                   so: get_total_refunded() === 0 means an admin reversed
     *                   the order outside WooCommerce, which is a full
     *                   reversal referenced to the ORDER, not to a refund id
     *
     * Any other status — one another plugin registered — is not ours to
     * interpret: it falls through and nothing happens.
     */
    private function route( int $order_id, string $to, \WC_Order $order ): void {
        if ( in_array( $to, SettingsHelper::award_statuses(), true ) ) {
            $this->run_step( 'award', $order_id, [ $this, 'on_awardable' ], $order_id );
            return;
        }

        switch ( $to ) {
            case 'pending':
            case 'on-hold':
                $this->run_step( 'stage', $order_id, [ $this, 'on_stageable' ], $order_id );
                break;
            case 'cancelled':
            case 'trash':
                $this->run_step( 'cancel', $order_id, [ $this, 'on_cancelled' ], $order_id );
                break;
            case 'refunded':
                if ( 0.0 === (float) $order->get_total_refunded() ) {
                    $this->run_step( 'refund', $order_id, [ $this, 'on_refunded' ], $order_id, 0 );
                }
                break;
            case 'failed':
            case 'checkout-draft':
                // Still payable (failed) or not yet an order (checkout-draft) — neither stages nor awards.
                break;
        }
    }

    /**
     * Runs one handler, logging instead of throwing when it is still a stub.
     *
     * TODO(step-2, step-3): delete once on_awardable/on_stageable/on_cancelled/on_refunded are implemented; route() then calls them directly.
     *
     * @param mixed ...$args
     */
    private function run_step( string $step, int $order_id, callable $handler, ...$args ): void {
        try {
            $handler( ...$args );
        } catch ( \RuntimeException $e ) {
            Log::warning( 'OrderListener', $step . ' skipped for order #' . $order_id . ': ' . $e->getMessage() );
        }
    }

    /** Evaluation moment: preview at the order's creation date, stage the figure. */
    public function on_stageable( int $order_id ): void {
        // TODO(F01-4): wc_get_order; ContextFactory::from_order( order, ledger->points_earned_today( user, occurred_at ) ); service->preview( PURCHASE, ctx ); pending->stage( order_id, user_id, points ).
    }

    /** Award moment: one award() per trigger the order satisfies (purchase, then Pro's order triggers). */
    public function on_awardable( int $order_id ): void {
        // TODO(F01-4): wc_get_order; same Context build as on_stageable; service->award( PURCHASE, ctx ); Pro adds first_order/order_count/spend_milestone via a filter over the trigger list.
    }

    /** Pending release for an order that will never award; also restores a redemption still applied to it. */
    public function on_cancelled( int $order_id ): void {
        // TODO(F01-4): pending->release( order_id ); redemption restore attaches here with Engine\Redemption\Reversal (F08), keyed on the order.
    }

    /**
     * Clawback seam. Refunds reverse earned points as refund_earn rows keyed
     * on the refund id; the logic is a later task and attaches here. Reached
     * from woocommerce_order_refunded, not from the `refunded` status.
     */
    public function on_refunded( int $order_id, int $refund_id ): void {
        // TODO(F01-4): clawback (refund_earn) — design pending; do not implement here until LedgerService gains a refund entry point.
    }
}
