<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\Customer;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_customers.
 *
 * Plain object, not a singleton — see docs/code-standards.md section 8.
 */
final class CustomerRepository extends AbstractRepository {

    /**
     * Logical sort key → join-qualified SQL expression, for the admin list's
     * ORDER BY. A different alphabet from sortable_columns() (bare columns
     * for the inherited paginate()): these need the `c`/`u` table aliases,
     * and 'expires' carries no nulls-last information sortable_columns()
     * has no way to express. Keep in sync with CustomerRowSort::KEYS.
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
     * Make sure a row exists for this user, without racing.
     *
     * CREATE-THEN-LOCK, NEVER CHECK-THEN-CREATE.
     *
     * SELECT ... FOR UPDATE on a row that does not exist takes a gap lock, not
     * a row lock, so two concurrent first-touch requests would both see nothing
     * and both insert. INSERT ... ON DUPLICATE KEY UPDATE makes the loser a
     * no-op instead, because user_id is the primary key.
     *
     * The `user_id = user_id` assignment is a deliberate no-op: it satisfies the
     * ON DUPLICATE KEY clause without touching a single stored value, so an
     * existing customer's aggregates are never disturbed by this call.
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
     * Read the row while holding a row lock on it.
     *
     * Must be called inside a transaction, and ensure_row() must have run first
     * — otherwise there is no row to lock and this takes a gap lock instead.
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
     * Apply a ledger row's effects to the cached aggregates, `balance`
     * included.
     *
     * Written as relative arithmetic (balance = balance + ?, lifetime_earned =
     * lifetime_earned + ?, ...) rather than absolute assignment, so each
     * statement is correct even if another writer touched the row between the
     * read and the write. In practice the caller holds the row lock, but the
     * relative form removes the assumption.
     *
     * `lifetime_earned`/`lifetime_redeemed` are not a plain running sum of
     * `points` the way `balance` is: LedgerType decides per-row whether a
     * movement counts toward them (LedgerService::apply_aggregates()), so that
     * decision is made in PHP before this call, not in SQL.
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

            // Activity clears the "expiring soon" reminder so the next window
            // sends one reminder, never two.
            $sets[] = 'reminder_sent_at = NULL';
        }

        if ( null !== $expires_at ) {
            $sets[]   = 'expires_at = %s';
            $values[] = $expires_at;
        }

        $values[] = $user_id;

        /*
         * $sets holds only literal SQL fragments written above in this method —
         * every one carries a placeholder and none is derived from caller input.
         * The values themselves go through prepare() in $values. The sniff
         * cannot see that the array is closed, hence the scoped ignore.
         */
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
     * The admin Customers list: search + filter + sort + paginate, INNER
     * JOINed with wp_users for name/email — this table has neither.
     *
     * An exception, alongside the read-only dashboard aggregates, to "a
     * repository never touches a second table" (see docs/code-standards.md
     * §6): that rule is about WRITE ownership between repositories. Nothing
     * here writes wp_users, no repository owns it, and
     * this read-side lookup against a table nothing in this codebase owns has
     * nowhere else to live. A loyalty row whose WP user was deleted is
     * correctly excluded (orphan cleanup is a separate concern).
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
                 FROM `' . $table . '` c INNER JOIN `' . $users . '` u ON u.ID = c.user_id'
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
     * Row count for one criteria set — shared by paginate_list() (the total)
     * and the "which filter to drop" suggestions (one call per candidate).
     *
     * @param array<string, mixed> $criteria CustomerRowFilters::to_criteria()'s shape.
     */
    public function count_list( array $criteria ): int {
        global $wpdb;

        $table = esc_sql( $this->table() );
        $users = esc_sql( $wpdb->users );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- list_where_sql()'s fragments are already prepared; identifiers are escaped above.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM `' . $table . '` c INNER JOIN `' . $users . '` u ON u.ID = c.user_id'
            . $this->list_where_sql( $criteria )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    private function list_where_sql( array $criteria ): string {
        $where = $this->where_fragments( $criteria );

        return empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
    }

    /**
     * Each fragment is bound through prepare() (or a literal with no caller
     * input) before it reaches here — never a raw criteria value.
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
            // No tiers table exists yet, so no tier name maps to a real
            // tier_id — every row's tier_id is NULL, so any non-'all'
            // selection can only ever match nothing. Intentional (see
            // CustomerRowFilters), not a bug: the filter UI stays real and
            // becomes meaningful for free once tiers ship.
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

        // Leading tie-break, not a second ORDER BY term: NULLs sort first in
        // MySQL ASC order, so "(col IS NULL) ASC" puts them last regardless
        // of $direction, matching CustomerRowSort::to_order()'s nulls_last.
        $nulls_last = $order['nulls_last'] ? "({$column} IS NULL) ASC, " : '';

        // Trailing tie-break on the primary key. Without it, rows tied on
        // $column have no defined relative order, and MySQL is free to
        // return them differently across two LIMIT/OFFSET calls with the
        // same ORDER BY — which is exactly what happens when
        // fetchAllMatchingCustomers() (Export CSV, bulk Adjust points) pages
        // through the same query one page at a time: ties can be silently
        // duplicated onto one page and dropped from another. user_id is
        // unique, so this makes every page's ordering fully deterministic.
        return $nulls_last . $column . ' ' . $direction . ', c.user_id ASC';
    }
}
