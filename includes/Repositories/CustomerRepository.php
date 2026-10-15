<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Models\Customer;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

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
     * Applies a ledger row's effects to the cached aggregates. Relative
     * arithmetic (balance = balance + ?), so it is correct even without the
     * row lock. Which deltas a row counts toward is decided by the caller.
     *
     * @param int         $balance_delta           Signed.
     * @param int         $lifetime_earned_delta   Signed.
     * @param int         $lifetime_redeemed_delta Signed.
     * @param string|null $activity_at             Set to touch last_activity_at.
     * @throws QueryException When the write fails.
     */
    public function apply_delta(
        int $user_id,
        int $balance_delta,
        int $lifetime_earned_delta,
        int $lifetime_redeemed_delta,
        ?string $activity_at = null
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
     * Sets customers.expires_at, null for none. LedgerService calls it under the row lock.
     *
     * @throws QueryException When the write fails.
     */
    public function set_expires_at( int $user_id, ?string $expires_at ): void {
        $this->update( $user_id, [ 'expires_at' => $expires_at ], [ '%s' ] );
    }

    /**
     * Customers with a balance whose expiry is due at $now, soonest first.
     *
     * @return int[]
     */
    public function due_user_ids( string $now, int $limit ): array {
        global $wpdb;

        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT user_id FROM `' . esc_sql( $this->table() ) . '`
                 WHERE expires_at IS NOT NULL AND expires_at <= %s AND balance > 0 ORDER BY expires_at LIMIT %d',
                $now,
                $limit
            )
        );

        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }

    /**
     * Customers due an inactivity expiry reminder at $now (reminder_due_sql()), soonest first.
     *
     * @return int[]
     */
    public function reminder_due_user_ids( string $now, string $horizon, int $limit ): array {
        global $wpdb;

        list( $due_sql, $due_values ) = self::reminder_due_sql( $now, $horizon );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $due_sql holds only placeholders; its values are bound.
        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT user_id FROM `' . esc_sql( $this->table() ) . "` WHERE {$due_sql} ORDER BY expires_at LIMIT %d",
                array_merge( $due_values, [ $limit ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }

    /**
     * Claims $user_id's inactivity reminder: reads the balance and date, then stamps reminder_sent_at = $now
     * and reminder_for = that date only while the row still holds those values and is still due. Of two
     * racing runs only one gets the row, and a write landing in between leaves it unclaimed for the next
     * run. Returns what to remind about, or null when not claimed.
     *
     * @return array{points: int, expires_at: string, is_single_date: bool}|null The whole balance, on one date.
     * @throws QueryException When the write fails.
     */
    public function claim_reminder( int $user_id, string $now, string $horizon ): ?array {
        global $wpdb;

        $customer   = $this->find_by_user_id( $user_id );
        $expires_at = null !== $customer ? $customer->get_expires_at() : null;

        if ( null === $expires_at ) {
            return null;
        }

        list( $due_sql, $due_values ) = self::reminder_due_sql( $now, $horizon );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $due_sql holds only placeholders, and $due_values binds them: the sniff cannot count through it.
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'UPDATE `' . esc_sql( $this->table() ) . "` SET reminder_sent_at = %s, reminder_for = expires_at
                 WHERE user_id = %d AND balance = %d AND expires_at = %s AND {$due_sql}",
                array_merge( [ $now, $user_id, $customer->get_balance(), $expires_at ], $due_values )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (claim_reminder)' );
        }

        if ( 1 !== $result ) {
            return null;
        }

        return [
            'points'         => $customer->get_balance(),
            'expires_at'     => $expires_at,
            'is_single_date' => true,
        ];
    }

    /**
     * Records that a batch reminder went out at $now. Batch claims live on lots; this only dates the last one.
     *
     * @throws QueryException When the write fails.
     */
    public function mark_reminded( int $user_id, string $now ): void {
        $this->update( $user_id, [ 'reminder_sent_at' => $now ], [ '%s' ] );
    }

    /**
     * The one inactivity reminder rule, for the selector and the claim alike: a balance whose date falls
     * after $now and by $horizon, and that date not yet announced (reminder_for). Changing the timing never
     * re-announces a date; a date that moves is announced again once.
     *
     * @return array{0: string, 1: array<int, string|int>} SQL with placeholders, and their values.
     */
    private static function reminder_due_sql( string $now, string $horizon ): array {
        return [
            'balance > 0 AND expires_at > %s AND expires_at <= %s AND ( reminder_for IS NULL OR reminder_for <> expires_at )',
            [ $now, $horizon ],
        ];
    }

    /**
     * Clears every expiry date, for when expiry is turned off.
     *
     * @throws QueryException When the write fails.
     */
    public function clear_expiry(): void {
        global $wpdb;

        $result = $wpdb->query( 'UPDATE `' . esc_sql( $this->table() ) . '` SET expires_at = NULL WHERE expires_at IS NOT NULL' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- no values; the table name is escaped.

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (clear_expiry)' );
        }
    }

    /**
     * Recomputes every inactivity expiry after a settings change: last activity (or joining) + $days for
     * customers with a balance; NULL for the rest. With $keep, never sooner than $floor or the stored date.
     * Without it, only a customer with no stored date (expiry just turned on) gets the $floor; a stored
     * date moves to the new one even when that is already past.
     *
     * @param string $floor UTC MySQL datetime, now + the grace period.
     * @param bool   $keep  Whether the admin kept stored dates on shortening.
     * @throws QueryException When the write fails.
     */
    public function restamp_inactivity_expiry( int $days, string $floor, bool $keep ): void {
        global $wpdb;

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'UPDATE `' . esc_sql( $this->table() ) . '`
                 SET expires_at = IF(
                     balance > 0,
                     IF(
                         %d = 1 OR expires_at IS NULL,
                         GREATEST(DATE_ADD(COALESCE(last_activity_at, joined_at), INTERVAL %d DAY), CAST(%s AS DATETIME), COALESCE(expires_at, CAST(%s AS DATETIME))),
                         DATE_ADD(COALESCE(last_activity_at, joined_at), INTERVAL %d DAY)
                     ),
                     NULL
                 )',
                (int) $keep,
                $days,
                $floor,
                $floor,
                $days
            )
        );

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (restamp_inactivity_expiry)' );
        }
    }

    /**
     * Sets every customer's expiry to their soonest open lot's, for batch expiry; NULL with no open lot.
     *
     * @throws QueryException When the write fails.
     */
    public function restamp_expiry_from_lots(): void {
        global $wpdb;

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'UPDATE `' . esc_sql( $this->table() ) . '` c
                 SET c.expires_at = (SELECT MIN(l.expires_at) FROM `' . esc_sql( Table::name( 'lots' ) ) . '` l WHERE l.user_id = c.user_id AND l.status = %s)',
                LotStatus::OPEN
            )
        );

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (restamp_expiry_from_lots)' );
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
