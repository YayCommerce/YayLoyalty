<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\LedgerRepository;

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
     * Attaches the one order hook; call once at boot, after settings are readable.
     *
     * One entry point on purpose. Per-status hooks
     * (`woocommerce_order_status_completed`) and `woocommerce_payment_complete`
     * describe the same transition from different angles, so registering more
     * than one of them processes each transition several times and leans on
     * the ledger's guard to undo the extra work.
     */
    public function register(): void {
        // TODO(F01-4): add_action woocommerce_order_status_changed → on_status_changed, 10, 4.
    }

    /**
     * The single order entry point: routes one transition by where it landed.
     *
     * Reads $to, never $order->get_status(): by the time a late-priority
     * callback runs the order may have moved again, and the transition being
     * handled is the one that was announced.
     *
     * $from is unused — first arrival wins and any later transition into the
     * award set composes the same idempotency key, so it costs one rejected
     * insert and returns Outcome::duplicate.
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
    public function on_status_changed( int $order_id, string $from, string $to, \WC_Order $order ): void {
        // TODO(F01-4): switch on $to exactly as the docblock lists; award when in_array( $to, award_statuses(), true ), checked before the single-status branches so a merchant who puts on-hold in the set still gets it.
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

    /**
     * Statuses that mean the money arrived, newest settings read each time
     * so a merchant's change applies to the next transition.
     *
     * @return string[]
     */
    private function award_statuses(): array {
        // TODO(F01-4): SettingsHelper::get_settings()['general']['award_statuses'], defaulting to [ 'processing', 'completed' ]; values are bare slugs, as woocommerce_order_status_changed reports them, never wc- prefixed.
        return [ 'processing', 'completed' ];
    }
}
