<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_ledger. Append-only: nothing here calls update() or delete(). Not final: CustomerActivityServiceTest extends it as a repository double. */
class LedgerRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'ledger';
    }

    protected function model_class(): string {
        return LedgerEntry::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'created_at', 'points', 'type' ];
    }

    /**
     * The row an idempotency key already produced, or null.
     *
     * Read twice on the award path, for different reasons. Before the insert
     * it is only an optimisation — the unique index is the guarantee, and
     * this SELECT merely avoids burning an AUTO_INCREMENT value on a
     * duplicate the caller can already see under its row lock. After a
     * collision it is how the writer names the row that won, which
     * Outcome::duplicate carries.
     */
    public function find_by_idempotency_key( string $key ): ?LedgerEntry {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE `idempotency_key` = %s LIMIT 1',
                $key
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $entry = $this->hydrate( $row );

        return $entry instanceof LedgerEntry ? $entry : null;
    }

    /**
     * The guarded insert: a UNIQUE `idempotency_key` collision is an answer
     * here, not a fault, so it comes back as null instead of an exception.
     * wpdb's error reporting is off for the attempt — the guard firing is
     * the system working, and it must not leave a line in the log that reads
     * like a broken write. Every other failure still throws.
     *
     * @return int|null Insert id, or null when the key was already present.
     * @throws QueryException When the insert failed for any other reason.
     */
    public function insert_unless_duplicate( LedgerEntry $entry ): ?int {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );

        try {
            return $this->insert( $entry );
        } catch ( QueryException $e ) {
            if ( ! $e->is_duplicate_key() ) {
                throw $e;
            }

            return null;
        } finally {
            $wpdb->suppress_errors( $suppress );
        }
    }

    /**
     * SUM(points) of earn rows for a user on the calendar day containing $at,
     * for CapPolicy's daily_cap. The day is the site-timezone day of the
     * event's own time, not of the request: a late award counts toward the
     * day the order was placed. 0 for a guest.
     *
     * @param string $at UTC MySQL datetime, the Context's occurred_at.
     */
    public function points_earned_today( ?int $user_id, string $at ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): site-tz day bounds around $at via Dates::to_site_tz(), back to UTC, then SUM(points) WHERE user_id AND type = earn AND created_at BETWEEN.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * One user's history, newest first.
     *
     * @return array{items: LedgerEntry[], total: int, page: int, per_page: int}
     */
    public function paginate_for_user( int $user_id, array $args = [] ): array {
        global $wpdb;

        $args['where'] = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];

        if ( ! isset( $args['orderby'] ) ) {
            $args['orderby'] = 'id';
        }

        return $this->paginate( $args );
    }

    /** COUNT(*) of a user's ledger rows — cheaper than paginate_for_user() when only the total is needed. */
    public function count_for_user( int $user_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d',
                $user_id
            )
        );
    }

    /** SUM(points) for a user, the ledger-side half of the balance invariant. Reserved for a rebuild tool. */
    public function sum_points_for_user( int $user_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COALESCE(SUM(points), 0) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d',
                $user_id
            )
        );
    }
}
