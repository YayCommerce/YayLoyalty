<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\Customer;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_customers. Readers that must not write depend on CustomerReader instead.
 * Not final: CustomerActivityServiceTest extends it as a repository double (mirrors DashboardRepository/CampaignRepository).
 */
class CustomerRepository extends AbstractRepository implements CustomerReader {

    /**
     * Sort key → join-qualified ORDER BY expression for the admin list.
     * Separate from sortable_columns(): these need the table aliases. Keep in
     * sync with CustomerRowSort::KEYS.
     */
    private const LIST_ORDER_COLUMNS = [
        'name'          => 'u.display_name',
        'balance'       => 'c.balance',
        'pending'       => 'c.pending_points',
        'lifetime'      => 'c.lifetime_earned',
        'last_activity' => 'c.last_activity_at',
        'expires'       => 'c.expires_at',
    ];

    protected function table_slug(): string {
        return 'customers';
    }

    protected function model_class(): string {
        return Customer::class;
    }

    protected function primary_key(): string {
        return 'user_id';
    }

    protected function sortable_columns(): array {
        return [ 'user_id', 'balance', 'lifetime_earned', 'lifetime_redeemed', 'last_activity_at', 'joined_at' ];
    }

    public function find_by_user_id( int $user_id ): ?Customer {
        $model = $this->find( $user_id );

        return $model instanceof Customer ? $model : null;
    }

    /**
     * Ensures a row exists without racing. Create-then-lock, never
     * check-then-create: FOR UPDATE on a missing row takes a gap lock, so two
     * first-touch requests would both insert. The `user_id = user_id`
     * assignment is a no-op that leaves an existing row untouched.
     *
     * @throws QueryException When the write fails.
     */
    public function ensure_row( int $user_id ): void {
        global $wpdb;

        $now = Dates::now();

        $sql = $wpdb->prepare(
            'INSERT INTO `' . esc_sql( $this->table() ) . '`
                (user_id, balance, pending_points, lifetime_earned, lifetime_redeemed, joined_at, updated_at)
             VALUES (%d, 0, 0, 0, 0, %s, %s)
             ON DUPLICATE KEY UPDATE user_id = user_id',
            $user_id,
            $now,
            $now
        );

        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        if ( false === $result ) {
            throw new QueryException( 'insert', $wpdb->last_error, $this->table() . ' (ensure_row)' );
        }
    }

    /**
     * Reads the row under a row lock. Inside a transaction, after ensure_row(): a missing row would gap-lock instead.
     *
     * @throws QueryException When the row is missing after ensure_row().
     */
    public function lock_and_get( int $user_id ): Customer {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d FOR UPDATE',
                $user_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            throw new QueryException(
                'select',
                $wpdb->last_error,
                sprintf( '%s: no row to lock for user %d — was ensure_row() called?', $this->table(), $user_id )
            );
        }

        return Customer::from_row( $row );
    }

    /**
     * Applies a ledger row's effects to the cached aggregates. Relative
     * arithmetic (balance = balance + ?), so it is correct even without the
     * row lock. Which deltas a row counts toward is decided by the caller.
     *
     * @param int         $balance_delta           Signed.
     * @param int         $lifetime_earned_delta   Signed.
     * @param int         $lifetime_redeemed_delta Signed.
     * @param string|null $activity_at             Set to touch last_activity_at.
     * @param string|null $expires_at              New expiry, or null to leave it.
     * @throws QueryException When the write fails.
     */
    public function apply_delta(
        int $user_id,
        int $balance_delta,
        int $lifetime_earned_delta,
        int $lifetime_redeemed_delta,
        ?string $activity_at = null,
        ?string $expires_at = null
    ): void {
        global $wpdb;

        $sets   = [
            'balance = balance + %d',
            'lifetime_earned = lifetime_earned + %d',
            'lifetime_redeemed = lifetime_redeemed + %d',
            'updated_at = %s',
        ];
        $values = [
            $balance_delta,
            $lifetime_earned_delta,
            $lifetime_redeemed_delta,
            Dates::now(),
        ];

        if ( null !== $activity_at ) {
            $sets[]   = 'last_activity_at = %s';
            $values[] = $activity_at;

            // Activity clears the reminder so the next expiry window sends one, never two.
            $sets[] = 'reminder_sent_at = NULL';
        }

        if ( null !== $expires_at ) {
            $sets[]   = 'expires_at = %s';
            $values[] = $expires_at;
        }

        $values[] = $user_id;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $sets contains only literal fragments defined in this method; all values are bound.
        $sql = $wpdb->prepare(
            'UPDATE `' . esc_sql( $this->table() ) . '` SET ' . implode( ', ', $sets ) . ' WHERE user_id = %d',
            $values
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (apply_delta)' );
        }
    }

    /**
     * Moves customers.pending_points by a signed delta (stage +N, release or
     * discard −N). Relative arithmetic like apply_delta(); the caller holds
     * the row lock when it also writes the ledger. Never below zero.
     *
     * @throws QueryException When the write fails.
     */
    public function apply_pending_delta( int $user_id, int $delta ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): UPDATE … SET pending_points = GREATEST(0, pending_points + %d), updated_at = %s WHERE user_id = %d; throw QueryException on false.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The admin Customers list: search, filter, sort, paginate, LEFT JOINed
     * with wp_users for name/email — LEFT, not INNER: a deleted member's
     * `customers` row must still appear (with `u.*` columns NULL,
     * `CustomerListFormatter::format_row()` falls back to the
     * `account_deleted` snapshot for display). A read-side exception to the
     * one-table rule (docs/code-standards.md § 6), which is about write
     * ownership.
     *
     * @param array<string, mixed>                                      $criteria CustomerRowFilters::to_criteria()'s shape.
     * @param array{column: string, direction: string, nulls_last: bool} $order    CustomerRowSort::to_order()'s shape.
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate_list( array $criteria, array $order, int $page, int $per_page ): array {
        global $wpdb;

        $page      = max( 1, $page );
        $per_page  = max( 1, min( $per_page, self::MAX_PER_PAGE ) );
        $total     = $this->count_list( $criteria );
        $where_sql = $this->list_where_sql( $criteria );
        $table     = esc_sql( $this->table() );
        $users     = esc_sql( $wpdb->users );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where_sql fragments are already prepared (see where_fragments()); identifiers are escaped above; only LIMIT/OFFSET are bound here.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT c.user_id, u.display_name AS name, u.user_email AS email,
                        c.balance, c.pending_points, c.lifetime_earned,
                        c.last_activity_at, c.expires_at
                 FROM `' . $table . '` c LEFT JOIN `' . $users . '` u ON u.ID = c.user_id'
                . $where_sql . ' ORDER BY ' . $this->order_by_sql( $order )
                . ' LIMIT %d OFFSET %d',
                $per_page,
                ( $page - 1 ) * $per_page
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return [
            'items'    => is_array( $rows ) ? $rows : [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Row count for one criteria set.
     *
     * @param array<string, mixed> $criteria CustomerRowFilters::to_criteria()'s shape.
     */
    public function count_list( array $criteria ): int {
        global $wpdb;

        $table = esc_sql( $this->table() );
        $users = esc_sql( $wpdb->users );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- list_where_sql()'s fragments are already prepared; identifiers are escaped above.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM `' . $table . '` c LEFT JOIN `' . $users . '` u ON u.ID = c.user_id'
            . $this->list_where_sql( $criteria )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    private function list_where_sql( array $criteria ): string {
        $where = $this->where_fragments( $criteria );

        return empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
    }

    /**
     * Every fragment is prepared here or a literal; never a raw criteria value.
     *
     * @param array<string, mixed> $criteria
     * @return string[]
     */
    private function where_fragments( array $criteria ): array {
        global $wpdb;

        $where = [];

        if ( '' !== $criteria['search'] ) {
            $like    = '%' . $wpdb->esc_like( $criteria['search'] ) . '%';
            $where[] = $wpdb->prepare( '(u.display_name LIKE %s OR u.user_email LIKE %s)', $like, $like );
        }

        if ( null !== $criteria['balance_min'] ) {
            $where[] = $wpdb->prepare( 'c.balance >= %d', $criteria['balance_min'] );
        }

        if ( null !== $criteria['balance_max'] ) {
            $where[] = $wpdb->prepare( 'c.balance <= %d', $criteria['balance_max'] );
        }

        if ( $criteria['expires_is_null'] ) {
            $where[] = 'c.expires_at IS NULL';
        } elseif ( null !== $criteria['expires_from'] ) {
            $where[] = $wpdb->prepare(
                'c.expires_at IS NOT NULL AND c.expires_at >= %s AND c.expires_at <= %s',
                $criteria['expires_from'],
                $criteria['expires_to']
            );
        }

        if ( null !== $criteria['tier'] ) {
            // Matches nothing until tiers ship (every tier_id is NULL). Intentional, see CustomerRowFilters.
            $where[] = 'c.tier_id IS NOT NULL';
        }

        return $where;
    }

    /**
     * @param array{column: string, direction: string, nulls_last: bool} $order
     */
    private function order_by_sql( array $order ): string {
        $column    = self::LIST_ORDER_COLUMNS[ $order['column'] ] ?? self::LIST_ORDER_COLUMNS['balance'];
        $direction = 'ASC' === $order['direction'] ? 'ASC' : 'DESC';

        // "(col IS NULL) ASC" puts NULLs last regardless of $direction.
        $nulls_last = $order['nulls_last'] ? "({$column} IS NULL) ASC, " : '';

        // Primary-key tie-break: without it, ties can land on two pages or none when paging through.
        return $nulls_last . $column . ' ' . $direction . ', c.user_id ASC';
    }
}
