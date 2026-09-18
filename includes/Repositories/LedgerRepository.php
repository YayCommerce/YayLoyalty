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
     * Finds a row by its idempotency key, matching uniq_ledger_event. Null
     * for a NULL reference: those never collide, so asking is meaningless.
     */
    public function find_by_reference( int $user_id, string $source, ?string $reference_type, ?int $reference_id ): ?LedgerEntry {
        global $wpdb;

        if ( null === $reference_type || null === $reference_id ) {
            return null;
        }

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND source = %s AND reference_type = %s AND reference_id = %d
                 LIMIT 1',
                $user_id,
                $source,
                $reference_type,
                $reference_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? LedgerEntry::from_row( $row ) : null;
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

    /** The most recent entry for a user. Uncalled today. */
    public function latest_for_user( int $user_id ): ?LedgerEntry {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d ORDER BY id DESC LIMIT 1',
                $user_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? LedgerEntry::from_row( $row ) : null;
    }
}
