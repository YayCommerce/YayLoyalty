<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The order event family. Builds a Context from the order — occurred_at
 * is the order's creation date both times — and hands it to the service;
 * no rules of its own. Every handler is safe to re-fire: staging and
 * release are idempotent and the award is guarded by the ledger.
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

    /** Attaches the WooCommerce hooks; call once at boot, after settings are readable. */
    public function register(): void {
        // TODO(F01-4): woocommerce_checkout_order_created + woocommerce_new_order → on_created; woocommerce_order_status_{award status from settings} → on_awardable; _cancelled/_failed → on_cancelled; woocommerce_order_refunded → on_refunded.
    }

    /** Evaluation moment: preview at the order's creation date, stage the figure. */
    public function on_created( int $order_id ): void {
        // TODO(F01-4): wc_get_order; ContextFactory::from_order( order, ledger->points_earned_today( user, occurred_at ) ); service->preview( PURCHASE, ctx ); pending->stage( order_id, user_id, points ).
    }

    /** Award moment: one award() per trigger the order satisfies (purchase, then Pro's order triggers). */
    public function on_awardable( int $order_id ): void {
        // TODO(F01-4): wc_get_order; same Context build as on_created; service->award( PURCHASE, ctx ); Pro adds first_order/order_count/spend_milestone via a filter over the trigger list.
    }

    /** Pending release for an order that will never award. */
    public function on_cancelled( int $order_id ): void {
        // TODO(F01-4): pending->release( order_id ).
    }

    /**
     * Clawback seam. Refunds reverse earned points as refund_earn rows keyed
     * on the refund id; the logic is a later task and attaches here.
     */
    public function on_refunded( int $order_id, int $refund_id ): void {
        // TODO(F01-4): clawback (refund_earn) — design pending; do not implement here until LedgerService gains a refund entry point.
    }
}
