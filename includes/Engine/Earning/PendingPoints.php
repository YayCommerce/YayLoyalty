<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The points an order holds as pending until it is awarded. Order meta is the truth, and
 * customers.pending_points is its cached sum. Every method is idempotent, and the award
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
     * Stages the preview figure once: while META_PENDING exists, another call is a no-op.
     * Returns whether this call staged. A guest gets order meta only.
     */
    public function stage( int $order_id, ?int $user_id, int $points ): bool {
        $order = wc_get_order( $order_id );

        if ( $points < 1 || ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META_PENDING ) ) {
            return false;
        }

        $order->update_meta_data( self::META_PENDING, $points );
        $this->save_meta( $order );

        if ( null !== $user_id ) {
            $this->customers->ensure_row( $user_id );
            $this->customers->apply_pending_delta( $user_id, $points );
        }

        return true;
    }

    /**
     * Releases the staged figure once. META_RELEASED guards it, not the figure, so a later status
     * bounce cannot release twice. Returns the amount released, or 0. The award calls it inside
     * its transaction, so the cache and the ledger move together.
     */
    public function release( int $order_id ): int {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order || '' !== (string) $order->get_meta( self::META_RELEASED ) ) {
            return 0;
        }

        $pending = (int) $order->get_meta( self::META_PENDING );

        if ( $pending < 1 ) {
            return 0;
        }

        $order->update_meta_data( self::META_RELEASED, Dates::now() );
        $this->save_meta( $order );

        // The order's customer now, not at staging: a reassigned order releases from its new owner (floored at 0).
        $user_id = (int) $order->get_customer_id();

        if ( $user_id > 0 ) {
            $this->customers->apply_pending_delta( $user_id, -$pending );
        }

        return $pending;
    }

    /**
     * Adds an awarded row to the order's projections: META_EARNED sums every
     * trigger's row, META_CAMPAIGN keeps the first row's campaign. Call once
     * per awarded row, never for a duplicate, or the sum double counts.
     */
    public function mark_awarded( int $order_id, LedgerEntry $entry ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $order->update_meta_data( self::META_EARNED, (int) $order->get_meta( self::META_EARNED ) + $entry->get_points() );

        if ( '' === (string) $order->get_meta( self::META_CAMPAIGN ) && null !== $entry->get_campaign_id() ) {
            $order->update_meta_data( self::META_CAMPAIGN, $entry->get_campaign_id() );
        }

        $this->save_meta( $order );
    }

    /**
     * Meta only, never a full save: these run inside the award transaction
     * under the customer lock, where woocommerce_update_order must not fire.
     * HPOS turns save_meta_data() into save() once date_modified is in the
     * past; the filter is WooCommerce's own switch for that.
     */
    private function save_meta( \WC_Order $order ): void {
        add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );

        try {
            $order->save_meta_data();
        } finally {
            remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );
        }
    }
}
