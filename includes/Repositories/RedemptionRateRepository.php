<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Sql;
use YayLoyalty\Utils\Table;
use YayLoyalty\Utils\WooOrders;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's redemption rate counts: of the members' orders, those that could have redeemed and those
 * that did. Reads WooCommerce's order table directly, HPOS or posts, so a window of many orders is one query
 * instead of one load per order. Writes nothing.
 */
class RedemptionRateRepository {

    /** Rows that are not placed orders: trashed, or drafts the classic and block checkouts start with. */
    private const EXCLUDED_STATUSES = [ 'trash', 'auto-draft', 'wc-checkout-draft' ];

    /**
     * Members' orders in any status but EXCLUDED_STATUSES (shop_order only) created in [$from_ts, $to_ts) that
     * redeemed, and that were eligible: redeemed, or placed with a balance of at least $threshold. The balance
     * is the customer's last ledger row before the order was created, 0 with none. A redeemed order is always
     * eligible, so the numerator is part of the denominator even after the threshold changes.
     *
     * @param int|null $threshold The fewest points any live reward takes; null when none is live, so only
     *                            redeemed orders are eligible.
     * @return array{eligible: int, redeemed: int}
     */
    public function counts( int $from_ts, int $to_ts, ?int $threshold ): array {
        global $wpdb;

        $ledger  = esc_sql( Table::name( 'ledger' ) );
        $members = esc_sql( Table::name( 'customers' ) );
        $orders  = self::orders_sql( Sql::in_placeholders( self::EXCLUDED_STATUSES ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table names are esc_sql()'d and $orders is a placeholder fragment built here; every value is bound.
        $row = $wpdb->get_row( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: one aggregate over the order table and the plugin's own tables, which wc_get_orders() cannot express.
            $wpdb->prepare(
                "SELECT COALESCE(SUM(r.redeemed), 0) AS redeemed,
                        COALESCE(SUM(r.redeemed = 1 OR r.balance_before >= %d), 0) AS eligible
                 FROM (
                     SELECT EXISTS (
                                SELECT 1 FROM `{$ledger}` lr WHERE lr.order_id = o.order_id AND lr.type = %s
                            ) AS redeemed,
                            COALESCE( (
                                SELECT lb.balance_after FROM `{$ledger}` lb
                                WHERE lb.user_id = o.user_id AND lb.created_at < o.created_at
                                ORDER BY lb.created_at DESC, lb.id DESC LIMIT 1
                            ), 0 ) AS balance_before
                     FROM ( {$orders} ) o
                     INNER JOIN `{$members}` m ON m.user_id = o.user_id
                 ) r",
                array_merge(
                    // No live reward: a balance can never reach PHP_INT_MAX, so only redeemed orders count.
                    [ null === $threshold ? PHP_INT_MAX : $threshold, LedgerType::REDEEM ],
                    self::EXCLUDED_STATUSES,
                    [ gmdate( Dates::MYSQL_FORMAT, $from_ts ), gmdate( Dates::MYSQL_FORMAT, $to_ts ) ]
                )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'eligible' => (int) ( $row['eligible'] ?? 0 ),
            'redeemed' => (int) ( $row['redeemed'] ?? 0 ),
        ];
    }

    /**
     * Customer orders as (order_id, user_id, created_at UTC), from whichever storage is authoritative.
     * Binds the excluded statuses, then the window's start and end.
     *
     * @param string $excluded_in The excluded statuses' "%s, %s" placeholder list.
     */
    private static function orders_sql( string $excluded_in ): string {
        global $wpdb;

        if ( WooOrders::is_hpos() ) {
            $table = esc_sql( \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name() );

            return "SELECT id AS order_id, customer_id AS user_id, date_created_gmt AS created_at FROM `{$table}`
                    WHERE type = 'shop_order' AND customer_id > 0 AND status NOT IN ({$excluded_in})
                    AND date_created_gmt >= %s AND date_created_gmt < %s";
        }

        return "SELECT p.ID AS order_id, CAST(cu.meta_value AS UNSIGNED) AS user_id, p.post_date_gmt AS created_at
                FROM `{$wpdb->posts}` p
                INNER JOIN `{$wpdb->postmeta}` cu ON cu.post_id = p.ID AND cu.meta_key = '_customer_user'
                WHERE p.post_type = 'shop_order' AND p.post_status NOT IN ({$excluded_in})
                AND p.post_date_gmt >= %s AND p.post_date_gmt < %s";
    }
}
