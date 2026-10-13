<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\Customer;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_customers. Readers that must not write depend on CustomerReader instead. */
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

        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- a direct query on the plugin's own table; $sql is prepared above.

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

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a locking read must reach the database, never a cache.
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
     * Sets when the customer's next points expire, null for never. Services\LotWriter calls it under the
     * customer lock, with the date Services\ExpiryRules gives.
     *
     * @throws QueryException When the write fails.
     */
    public function set_expires_at( int $user_id, ?string $expires_at ): void {
        $this->update( $user_id, [ 'expires_at' => $expires_at ], [ '%s' ] );
    }

    /**
     * Customers whose points are due to expire by $now, earliest first, with the date the job passes to
     * LedgerService::expire().
     *
     * @return array<int, array{user_id: int, expires_at: string}>
     */
    public function due_for_expiry( string $now, int $limit ): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT user_id, expires_at FROM `' . esc_sql( $this->table() ) . '`
                 WHERE expires_at IS NOT NULL AND expires_at <= %s AND balance > 0
                 ORDER BY expires_at, user_id LIMIT %d',
                $now,
                $limit
            ),
            ARRAY_A
        );

        return array_map(
            static fn( array $row ): array => [
                'user_id'    => (int) $row['user_id'],
                'expires_at' => (string) $row['expires_at'],
            ],
            is_array( $rows ) ? $rows : []
        );
    }

    /**
     * Customers a reminder is due for, earliest expiry first: a positive balance expiring in the future, and
     * for some window d, within d days and not yet handled for that window (reminder_sent_at before
     * expires_at - d, or never).
     *
     * @param int[] $windows Window lengths in days, each above 0.
     * @return Customer[]
     */
    public function due_for_reminder( string $now, array $windows, int $limit ): array {
        global $wpdb;

        if ( empty( $windows ) ) {
            return [];
        }

        $clauses = [];
        $values  = [ $now ];

        foreach ( $windows as $days ) {
            $clauses[] = '( expires_at <= DATE_ADD( %s, INTERVAL %d DAY ) AND ( reminder_sent_at IS NULL OR reminder_sent_at < DATE_SUB( expires_at, INTERVAL %d DAY ) ) )';
            array_push( $values, $now, (int) $days, (int) $days );
        }

        $values[] = $limit;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $clauses are literal fragments built above; every value is bound.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '`
                 WHERE balance > 0 AND expires_at > %s AND ( ' . implode( ' OR ', $clauses ) . ' )
                 ORDER BY expires_at, user_id LIMIT %d',
                $values
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return array_map( [ Customer::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
    }

    /**
     * Stamps that the expiry job handled a reminder for the customer at $at, whether it sent one or skipped.
     *
     * @throws QueryException When the write fails.
     */
    public function mark_reminded( int $user_id, string $at ): void {
        $this->update( $user_id, [ 'reminder_sent_at' => $at ], [ '%s' ] );
    }

    /**
     * Re-dates every customer under the batch model, as ExpiryRules::customer_expires_at() would one by
     * one: their earliest open lot's expiry when the balance is positive, else null. Reads `lots` in a
     * join, as the admin list joins users; it writes only this table.
     *
     * @throws QueryException When the write fails.
     */
    public function recompute_expiry_from_lots(): void {
        global $wpdb;

        $table = esc_sql( $this->table() );
        $lots  = esc_sql( ( new LotRepository() )->table() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a set-wide update on the plugin's own escaped tables; the status is bound.
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` c
                 LEFT JOIN ( SELECT user_id, MIN( expires_at ) AS earliest FROM `{$lots}` WHERE status = %s GROUP BY user_id ) l
                   ON l.user_id = c.user_id
                 SET c.expires_at = CASE WHEN c.balance > 0 THEN l.earliest ELSE NULL END",
                LotRepository::STATUS_OPEN
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (recompute_expiry_from_lots)' );
        }
    }

    /**
     * Re-dates every customer under the inactivity model, as ExpiryRules::customer_expires_at() would one
     * by one: $days after the last earn or redeem (or joining), for a positive balance only. Null clears
     * every date, for expiry turned off. One statement, so it runs without the customer locks.
     *
     * @throws QueryException When the write fails.
     */
    public function recompute_inactivity_expiry( ?int $days ): void {
        global $wpdb;

        $table = esc_sql( $this->table() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a set-wide update on the plugin's own escaped table; the day count is bound.
        $result = null === $days
            ? $wpdb->query( "UPDATE `{$table}` SET expires_at = NULL WHERE expires_at IS NOT NULL" )
            : $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET expires_at = CASE
                        WHEN balance > 0 THEN DATE_ADD( COALESCE( last_activity_at, joined_at ), INTERVAL %d DAY )
                        ELSE NULL END",
                    $days
                )
            );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (recompute_inactivity_expiry)' );
        }
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

        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- a direct query on the plugin's own table; $sql is prepared above.

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (apply_delta)' );
        }
    }

    /**
     * Moves customers.pending_points by a signed delta (stage +N, release or
     * discard −N). Relative arithmetic like apply_delta(). The caller holds
     * the row lock when it also writes the ledger. Never below zero.
     *
     * @throws QueryException When the write fails.
     */
    public function apply_pending_delta( int $user_id, int $delta ): void {
        global $wpdb;

        // The column is unsigned: MySQL rejects or wraps an underflow, so the floor is applied in SQL.
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'UPDATE `' . esc_sql( $this->table() ) . '` SET pending_points = GREATEST(0, CAST(pending_points AS SIGNED) + %d), updated_at = %s WHERE user_id = %d',
                $delta,
                Dates::now(),
                $user_id
            )
        );

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (apply_pending_delta)' );
        }
    }

    /**
     * The admin Customers list: search, filter, sort and paginate, LEFT JOINed with wp_users for name
     * and email. LEFT, not INNER: a deleted member's `customers` row must still appear. Its `u.*` columns
     * are NULL, and CustomerListFormatter::format_row() falls back to the `account_deleted` snapshot.
     * This is a read-side exception to the one-table rule, which is about write ownership.
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
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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
     * Every fragment is prepared here or a literal, never a raw criteria value.
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

        return $where;
    }

    /** @param array{column: string, direction: string, nulls_last: bool} $order */
    private function order_by_sql( array $order ): string {
        $column    = self::LIST_ORDER_COLUMNS[ $order['column'] ] ?? self::LIST_ORDER_COLUMNS['balance'];
        $direction = 'ASC' === $order['direction'] ? 'ASC' : 'DESC';

        // "(col IS NULL) ASC" puts NULLs last regardless of $direction.
        $nulls_last = $order['nulls_last'] ? "({$column} IS NULL) ASC, " : '';

        // Primary-key tie-break: without it, ties can land on two pages or none when paging through.
        return $nulls_last . $column . ' ' . $direction . ', c.user_id ASC';
    }
}
