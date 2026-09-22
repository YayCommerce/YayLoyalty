<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_ledger. Append-only: nothing here calls update() or delete(). */
final class LedgerRepository extends AbstractRepository {

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
     * The row an idempotency key already produced, or null. An optimisation
     * for the award path only: the unique index is the guarantee, this SELECT
     * merely avoids burning an AUTO_INCREMENT value on a known duplicate.
     */
    public function find_by_idempotency_key( string $key ): ?LedgerEntry { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): SELECT * … WHERE idempotency_key = %s LIMIT 1, hydrate via LedgerEntry::from_row().
        throw new \RuntimeException( 'Not implemented' );
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
