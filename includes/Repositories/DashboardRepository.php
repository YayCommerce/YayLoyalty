<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Sql;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only dashboard aggregates across ledger, customers, orders
 * and wp_users. Not an AbstractRepository (that is one table) and writes
 * nothing. Every ledger predicate carries `type IN (...)` so the
 * type_created index range-scans. Windows are UTC MySQL strings, `from`
 * inclusive, `to` exclusive.
 */
class DashboardRepository {

    /** Customers whose loyalty row existed before $before_utc. */
    public function count_members( string $before_utc ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare( 'SELECT COUNT(*) FROM `' . esc_sql( Table::name( 'customers' ) ) . '` WHERE joined_at < %s', $before_utc )
        );
    }

    public function sum_issued( string $from, string $to ): int {
        list( $issued, $types ) = LedgerRepository::issued_row_sql();

        return $this->sum_points( $issued, $types, $from, $to );
    }

    public function sum_redeemed( string $from, string $to ): int {
        return abs( $this->sum_points( 'type = %s', [ LedgerType::REDEEM ], $from, $to ) );
    }

    /** Signed movement of every type in the window. It rewinds liability approximately. */
    public function net_delta( string $from, string $to ): int {
        return $this->sum_points( 'type IN (' . Sql::in_placeholders( LedgerType::all() ) . ')', LedgerType::all(), $from, $to );
    }

    /**
     * Issued and redeemed points per site-timezone day. Only days with activity appear: the caller zero-fills.
     *
     * @return array<int, array{date: string, issued: string, redeemed: string}>
     */
    public function daily_points( string $from, string $to, int $offset_seconds ): array {
        global $wpdb;

        list( $issued, $earn_types ) = LedgerRepository::issued_row_sql();
        $all_in                      = Sql::in_placeholders( LedgerType::all() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $issued/$all_in carry "%s, %s" lists; every value is bound below.
        $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS date,
                        SUM(CASE WHEN {$issued} THEN points ELSE 0 END) AS issued,
                        SUM(CASE WHEN type = %s THEN -points ELSE 0 END) AS redeemed
                 FROM `" . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE type IN ({$all_in}) AND created_at >= %s AND created_at < %s
                 GROUP BY 1 ORDER BY 1",
                array_merge( [ $offset_seconds ], $earn_types, [ LedgerType::REDEEM ], LedgerType::all(), [ $from, $to ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return is_array( $rows ) ? $rows : [];
    }

    /** Outstanding liability: only balances the store still owes. */
    public function liability_points(): int {
        global $wpdb;

        return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(balance), 0) FROM `' . esc_sql( Table::name( 'customers' ) ) . '` WHERE balance > 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- a direct query on escaped table names; it takes no values.
    }

    /** Customers below zero who still have a WordPress user. */
    public function count_negative_balances(): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- a direct query on escaped table names; it takes no values.
            'SELECT COUNT(*) FROM `' . esc_sql( Table::name( 'customers' ) ) . '` c INNER JOIN `' . esc_sql( $wpdb->users ) . '` u ON u.ID = c.user_id WHERE c.balance < 0'
        );
    }

    /** Signed total of every balance. With net_delta(), it gives the previous period's liability. */
    public function net_balance(): int {
        global $wpdb;

        return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(balance), 0) FROM `' . esc_sql( Table::name( 'customers' ) ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- a direct query on escaped table names; it takes no values.
    }

    /** Distinct orders that earned points. Issued rows only: a clawback is not an award. */
    public function orders_with_earn( string $from, string $to ): int {
        list( $issued, $types ) = LedgerRepository::issued_row_sql();

        return $this->count_distinct_orders( $issued, $types, $from, $to );
    }

    /**
     * Orders and points one campaign earned inside a window.
     *
     * @return array{orders: int, points: int}
     */
    public function campaign_points_between( int $campaign_id, string $from, string $to ): array {
        global $wpdb;

        list( $issued, $types ) = LedgerRepository::issued_row_sql();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $issued carries a "%s, %s" list; every value is bound.
        $row = $wpdb->get_row( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT order_id) AS orders, COALESCE(SUM(points), 0) AS points FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE campaign_id = %d AND {$issued} AND created_at >= %s AND created_at < %s",
                array_merge( [ $campaign_id ], $types, [ $from, $to ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'orders' => (int) ( $row['orders'] ?? 0 ),
            'points' => (int) ( $row['points'] ?? 0 ),
        ];
    }

    /** @return array<int, array{user_id: int, name: string, balance: int}> */
    public function top_balances( int $limit ): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT c.user_id, u.display_name AS name, c.balance
                 FROM `' . esc_sql( Table::name( 'customers' ) ) . '` c INNER JOIN `' . esc_sql( $wpdb->users ) . '` u ON u.ID = c.user_id
                 WHERE c.balance > 0 ORDER BY c.balance DESC, c.user_id DESC LIMIT %d',
                $limit
            ),
            ARRAY_A
        );

        return array_map(
            static fn( array $row ): array => [
                'user_id' => (int) $row['user_id'],
                'name'    => (string) $row['name'],
                'balance' => (int) $row['balance'],
            ],
            is_array( $rows ) ? $rows : []
        );
    }

    /**
     * Newest ledger rows with the actor's display name.
     *
     * @return array<int, array<string, mixed>> LedgerEntry::to_array() + `name`.
     */
    public function latest_activity( int $limit ): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT l.*, u.display_name AS name
                 FROM `' . esc_sql( Table::name( 'ledger' ) ) . '` l INNER JOIN `' . esc_sql( $wpdb->users ) . '` u ON u.ID = l.user_id
                 ORDER BY l.id DESC LIMIT %d',
                $limit
            ),
            ARRAY_A
        );

        return array_map(
            static fn( array $row ): array => LedgerEntry::from_row( $row )->to_array() + [ 'name' => (string) $row['name'] ],
            is_array( $rows ) ? $rows : []
        );
    }

    /** Ledger rows written since $from_utc, any type. */
    public function count_activity_since( string $from_utc ): int {
        global $wpdb;

        $all_in = Sql::in_placeholders( LedgerType::all() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $all_in is a "%s, %s" list; every value is bound.
        return (int) $wpdb->get_var( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( Table::name( 'ledger' ) ) . "` WHERE type IN ({$all_in}) AND created_at >= %s",
                array_merge( LedgerType::all(), [ $from_utc ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * Completed orders created in [$from_ts, $to_ts). Timestamps, not Y-m-d: a date would parse
     * as a site-local day and drift from the UTC ledger window.
     */
    public function count_orders( int $from_ts, int $to_ts ): int {
        return $this->count_wc_orders( [ 'wc-completed' ], $from_ts, $to_ts );
    }

    /**
     * WooCommerce orders in $statuses created in [$from_ts, $to_ts), on HPOS or posts storage. Only shop_order:
     * wc_get_orders()' default types include refunds, which are always 'completed'.
     *
     * @param string[] $statuses
     */
    private function count_wc_orders( array $statuses, int $from_ts, int $to_ts ): int {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }

        $result = wc_get_orders(
            [
                'type'         => 'shop_order',
                'date_created' => $from_ts . '...' . ( $to_ts - 1 ),
                'status'       => $statuses,
                'return'       => 'ids',
                'paginate'     => true,
                'limit'        => 1,
            ]
        );

        return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
    }

    /**
     * @param string  $where Prepared-SQL row predicate from this class or LedgerRepository::issued_row_sql().
     * @param mixed[] $args  The values `$where` binds, in order.
     */
    private function sum_points( string $where, array $args, string $from, string $to ): int {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is a placeholder fragment built in this plugin; every value is bound.
        return (int) $wpdb->get_var( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COALESCE(SUM(points), 0) FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE {$where} AND created_at >= %s AND created_at < %s",
                array_merge( $args, [ $from, $to ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * @param string  $where Prepared-SQL row predicate from this class or LedgerRepository::issued_row_sql().
     * @param mixed[] $args  The values `$where` binds, in order.
     */
    private function count_distinct_orders( string $where, array $args, string $from, string $to ): int {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where is a placeholder fragment built in this plugin; every value is bound.
        return (int) $wpdb->get_var( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT order_id) FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE {$where} AND created_at >= %s AND created_at < %s AND order_id IS NOT NULL",
                array_merge( $args, [ $from, $to ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }
}
