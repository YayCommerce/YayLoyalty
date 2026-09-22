<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The staging store between the two moments: the preview figure an order
 * carries until it is awarded. Truth is order meta (HPOS-safe through
 * WC_Order); customers.pending_points is its cached sum. Every method is
 * idempotent so any hook may call it any number of times, and the award
 * never depends on staging having happened.
 */
final class PendingPoints {

    /** The staged figure; its presence is the "already staged" key. */
    public const META_PENDING = '_yayloyalty_points_pending';

    /** The release flag, separate from the figure: set exactly once, at award or cancel. */
    public const META_RELEASED = '_yayloyalty_points_released';

    /** Projections written at award for the order screen and emails; never read to compute a balance. */
    public const META_EARNED   = '_yayloyalty_points_earned';
    public const META_CAMPAIGN = '_yayloyalty_campaign_id';

    private CustomerRepository $customers;

    public function __construct( CustomerRepository $customers ) {
        $this->customers = $customers;
    }

    /**
     * Stages the preview figure once. Keyed on META_PENDING being absent:
     * a second call from another hook is a no-op, so several hooks may stage.
     * Postcondition: META_PENDING = $points and customers.pending_points
     * moved by $points, or nothing changed. Returns whether this call staged.
     * Guests (null user) get order meta only. $points 0 stages nothing.
     */
    public function stage( int $order_id, ?int $user_id, int $points ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): wc_get_order; return false if META_PENDING present or $points < 1; update_meta_data + save; apply_pending_delta( user, +points ) when user; true.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Releases the staged figure exactly once, guarded by META_RELEASED, not
     * by the figure's presence. Returns the released amount, 0 when nothing
     * was staged or it was released already — the award path ignores the
     * value and must succeed when staging never ran. Called inside the award
     * transaction so the cache and the ledger move together, and on cancel
     * or failure, where the same flag stops a later status bounce into the
     * award status from releasing twice.
     */
    public function release( int $order_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): wc_get_order; return 0 if META_RELEASED set or META_PENDING absent; set META_RELEASED = created UTC; apply_pending_delta( user, −pending ); return pending.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Writes the award projections (META_EARNED, META_CAMPAIGN) after the
     * ledger row exists. Idempotent: rewrites the same values on a duplicate.
     */
    public function mark_awarded( int $order_id, LedgerEntry $entry ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): wc_get_order; update_meta_data META_EARNED = points, META_CAMPAIGN = campaign_id; save.
        throw new \RuntimeException( 'Not implemented' );
    }
}
