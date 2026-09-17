<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only aggregates for the admin dashboard across ledger, customers,
 * rewards, WooCommerce orders and wp_users.
 *
 * Deliberately NOT an AbstractRepository: that base is "all SQL for exactly
 * one table" and this class reads five (the fifth being an INNER JOIN against
 * wp_users for names). It writes nothing — the ledger stays LedgerService's
 * alone (docs/code-standards.md §6), and the one-table repositories keep
 * their write ownership.
 *
 * Every ledger predicate carries `type IN (...)` so MySQL can range-scan
 * `type_created`; `customers` reads sit on the `balance` / `joined_at`
 * indexes. Window arguments are UTC MySQL strings from
 * Services\Dashboard\PeriodWindow (`from` inclusive, `to` exclusive).
 *
 * Not final: DashboardStatsTest stubs it with an anonymous subclass.
 */
class DashboardRepository {

    /** @var RewardRepository|null */
    private $rewards;

    public function __construct( ?RewardRepository $rewards = null ) {
        $this->rewards = $rewards;
    }

    /** Customers whose loyalty row existed before $before_utc. */
    public function count_members( string $before_utc ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare( 'SELECT COUNT(*) FROM `' . esc_sql( Table::name( 'customers' ) ) . '` WHERE joined_at < %s', $before_utc )
        );
    }

    /**
     * Points issued: positive rows of the types that feed lifetime_earned —
     * the same population as customers.lifetime_earned, so a positive manual
     * adjustment counts and the figure is verifiable on a dev store today.
     */
    public function sum_issued( string $from, string $to ): int {
        return $this->sum_points( $this->earn_types(), 'points > 0', $from, $to );
    }

    public function sum_redeemed( string $from, string $to ): int {
        return abs( $this->sum_points( [ LedgerType::REDEEM ], '1 = 1', $from, $to ) );
    }

    /** Signed movement of every type in the window — rewinds liability approximately. */
    public function net_delta( string $from, string $to ): int {
        return $this->sum_points( LedgerType::all(), '1 = 1', $from, $to );
    }

    /**
     * Issued/redeemed per site-timezone calendar day. Rows only for days
     * with activity; the caller zero-fills with DashboardMath::fill_series.
     *
     * @return array<int, array{date: string, issued: string, redeemed: string}>
     */
    public function daily_points( string $from, string $to, int $offset_seconds ): array {
        global $wpdb;

        $earn_in = $this->placeholders( $this->earn_types() );
        $all_in  = $this->placeholders( LedgerType::all() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $earn_in/$all_in are "%s, %s" lists built by placeholders(); every value is bound below.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS date,
                        SUM(CASE WHEN points > 0 AND type IN ({$earn_in}) THEN points ELSE 0 END) AS issued,
                        SUM(CASE WHEN type = %s THEN -points ELSE 0 END) AS redeemed
                 FROM `" . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE type IN ({$all_in}) AND created_at >= %s AND created_at < %s
                 GROUP BY 1 ORDER BY 1",
                array_merge( [ $offset_seconds ], $this->earn_types(), [ LedgerType::REDEEM ], LedgerType::all(), [ $from, $to ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return is_array( $rows ) ? $rows : [];
    }

    /** Outstanding liability: only balances the store still owes. */
    public function liability_points(): int {
        global $wpdb;

        return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(balance), 0) FROM `' . esc_sql( Table::name( 'customers' ) ) . '` WHERE balance > 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Customers currently below zero — the same INNER JOIN as the Customers
     * list so the notice count matches what its "Review" deep link shows.
     */
    public function count_negative_balances(): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            'SELECT COUNT(*) FROM `' . esc_sql( Table::name( 'customers' ) ) . '` c INNER JOIN `' . esc_sql( $wpdb->users ) . '` u ON u.ID = c.user_id WHERE c.balance < 0'
        );
    }

    /** Signed total of every balance — with net_delta(), the previous period's liability. */
    public function net_balance(): int {
        global $wpdb;

        return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(balance), 0) FROM `' . esc_sql( Table::name( 'customers' ) ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Distinct orders on which points were redeemed. */
    public function orders_with_points( string $from, string $to ): int {
        return $this->count_distinct_orders( [ LedgerType::REDEEM ], $from, $to );
    }

    /** Distinct orders that earned points (positive rows only — a refund_earn clawback is not an award). */
    public function orders_with_earn( string $from, string $to ): int {
        return $this->count_distinct_orders( $this->earn_types(), $from, $to, 'AND points > 0' );
    }

    /**
     * Orders and points one campaign earned inside a window (the anomaly
     * panel's "what changed" row). `campaign_id` index.
     *
     * @return array{orders: int, points: int}
     */
    public function campaign_points_on_day( int $campaign_id, string $from, string $to ): array {
        global $wpdb;

        $in = $this->placeholders( $this->earn_types() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a "%s, %s" list; every value is bound.
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT order_id) AS orders, COALESCE(SUM(points), 0) AS points FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE campaign_id = %d AND points > 0 AND type IN ({$in}) AND created_at >= %s AND created_at < %s",
                array_merge( [ $campaign_id ], $this->earn_types(), [ $from, $to ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'orders' => (int) ( $row['orders'] ?? 0 ),
            'points' => (int) ( $row['points'] ?? 0 ),
        ];
    }

    /**
     * @return array<int, array{user_id: int, name: string, balance: int}>
     */
    public function top_earners( int $limit ): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

        $all_in = $this->placeholders( LedgerType::all() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $all_in is a "%s, %s" list; every value is bound.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( Table::name( 'ledger' ) ) . "` WHERE type IN ({$all_in}) AND created_at >= %s",
                array_merge( LedgerType::all(), [ $from_utc ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * Completed WooCommerce orders created in [$from_ts, $to_ts). Unix
     * timestamps on purpose: a `Y-m-d` range would be parsed as site-local
     * whole days and drift from the UTC ledger window.
     */
    public function count_orders( int $from_ts, int $to_ts ): int {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return 0;
        }

        $result = wc_get_orders(
            [
                'date_created' => $from_ts . '...' . ( $to_ts - 1 ),
                'status'       => [ 'wc-completed' ],
                'return'       => 'ids',
                'paginate'     => true,
                'limit'        => 1,
            ]
        );

        return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
    }

    /**
     * The liability valuation rate: the built-in flexible-discount reward's
     * `points_per_currency_unit`. 0 when the reward or key is missing.
     */
    public function points_per_currency_unit(): float {
        $rewards = $this->rewards ?? new RewardRepository();
        $reward  = $rewards->find_by_slug( 'cart-discount' );

        return null === $reward ? 0.0 : (float) ( $reward->get_config()['points_per_currency_unit'] ?? 0 );
    }

    /**
     * @param string[] $types
     */
    private function sum_points( array $types, string $extra_where, string $from, string $to ): int {
        global $wpdb;

        $in = $this->placeholders( $types );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a "%s, %s" list, $extra_where a literal fragment from this class; every value is bound.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COALESCE(SUM(points), 0) FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE type IN ({$in}) AND created_at >= %s AND created_at < %s AND {$extra_where}",
                array_merge( $types, [ $from, $to ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * @param string[] $types
     * @param string   $extra_where Literal fragment from this class.
     */
    private function count_distinct_orders( array $types, string $from, string $to, string $extra_where = '' ): int {
        global $wpdb;

        $in = $this->placeholders( $types );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a "%s, %s" list, $extra_where a literal fragment from this class; every value is bound.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT order_id) FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE type IN ({$in}) AND created_at >= %s AND created_at < %s AND order_id IS NOT NULL {$extra_where}",
                array_merge( $types, [ $from, $to ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
    }

    /**
     * @return string[] Types that feed customers.lifetime_earned.
     */
    private function earn_types(): array {
        return array_values( array_filter( LedgerType::all(), [ LedgerType::class, 'affects_lifetime_earned' ] ) );
    }

    /**
     * @param string[] $values
     */
    private function placeholders( array $values ): string {
        return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
    }
}
