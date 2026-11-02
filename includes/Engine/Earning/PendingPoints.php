<?php
namespace YayLoyalty\Engine\Earning;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Utilities\OrderUtil;
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
    public const META_RELEASED_AT = '_yayloyalty_points_released_at';

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
     * Releases the staged figure once. META_RELEASED_AT guards it, not the figure, so a later status
     * bounce cannot release twice. Returns the amount released, or 0. The award calls it inside
     * its transaction, so the cache and the ledger move together.
     */
    public function release( int $order_id ): int {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return 0;
        }

        $pending = self::unreleased( $order );

        if ( $pending < 1 ) {
            return 0;
        }

        $order->update_meta_data( self::META_RELEASED_AT, Dates::now() );
        $this->save_meta( $order );

        // The order's customer now, not at staging: a reassigned order releases from its new owner (floored at 0).
        $user_id = (int) $order->get_customer_id();

        if ( $user_id > 0 ) {
            $this->customers->apply_pending_delta( $user_id, -$pending );
        }

        return $pending;
    }

    /** The staged figure while the order has not been released, else 0. */
    public static function unreleased( \WC_Order $order ): int {
        return '' === (string) $order->get_meta( self::META_RELEASED_AT ) ? (int) $order->get_meta( self::META_PENDING ) : 0;
    }

    /**
     * Adds an awarded row to the order's projections: META_EARNED sums every
     * trigger's row, META_CAMPAIGN keeps the first row's campaign. Call once
     * per awarded row, never for a duplicate, or the sum double counts.
     */
    public function mark_awarded( int $order_id, LedgerEntry $entry ): void {
        $this->write_earned( $order_id, $entry, true );
    }

    /**
     * Sets META_EARNED to $entry's points, rather than adding to it as mark_awarded() does, and
     * META_CAMPAIGN as mark_awarded() does. For a recovery: the meta may already claim points the
     * ledger never kept (an award rolled back after its meta reached a persistent object cache), and
     * adding would double it.
     */
    public function restate_earned( int $order_id, LedgerEntry $entry ): void {
        $this->write_earned( $order_id, $entry, false );
    }

    /**
     * META_EARNED set to, or with $is_additive raised by, $entry's points; META_CAMPAIGN set to its
     * campaign while the order has none.
     */
    private function write_earned( int $order_id, LedgerEntry $entry, bool $is_additive ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $earned = $is_additive ? (int) $order->get_meta( self::META_EARNED ) + $entry->get_points() : $entry->get_points();

        $order->update_meta_data( self::META_EARNED, $earned );

        if ( '' === (string) $order->get_meta( self::META_CAMPAIGN ) && null !== $entry->get_campaign_id() ) {
            $order->update_meta_data( self::META_CAMPAIGN, $entry->get_campaign_id() );
        }

        $this->save_meta( $order );
    }

    /**
     * Drops WooCommerce's cached copy of the order and its meta, so the next wc_get_order() reads the
     * database. Transaction rolls back the database, not the object cache: after an award that failed
     * mid-way, a persistent object cache (Redis, Memcached) can still serve the rolled-back meta, an
     * order that claims points the ledger never kept.
     */
    public function forget_cached( int $order_id ): void {
        clean_post_cache( $order_id );

        if ( class_exists( OrderUtil::class ) && OrderUtil::orders_cache_usage_is_enabled() ) {
            wc_get_container()->get( OrderCache::class )->remove( $order_id );
        }
    }

    /**
     * Recomputes customers.pending_points from order meta: the sum of META_PENDING over the
     * customer's orders without META_RELEASED_AT. Order meta is the truth and pending_points its
     * cache, so a release that really happened is never subtracted twice.
     *
     * @return int The new pending_points.
     */
    public function resync_customer( int $user_id ): int {
        $order_ids = wc_get_orders(
            [
                'customer_id' => $user_id,
                'limit'       => -1,
                'return'      => 'ids',
                'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one customer's orders, on a manual recovery.
                    [
                        'key'     => self::META_PENDING,
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key'     => self::META_RELEASED_AT,
                        'compare' => 'NOT EXISTS',
                    ],
                ],
            ]
        );

        $pending = 0;

        foreach ( is_array( $order_ids ) ? $order_ids : [] as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( $order instanceof \WC_Order ) {
                $pending += self::unreleased( $order );
            }
        }

        $this->customers->set_pending_points( $user_id, $pending );

        return $pending;
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
