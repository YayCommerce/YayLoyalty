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
}
