<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_ledger.
 *
 * Append-only: this repository exposes no update() or delete() usage. Rows are
 * inserted and read, never changed. (The base class still defines them; GDPR
 * erasure will be the only caller, and it gets its own reviewed method.)
 *
 * Plain object, not a singleton — see docs/code-standards.md section 8.
 */
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
     * Find an existing row by its idempotency key: (user_id, source,
     * reference_type, reference_id) — matches `uniq_ledger_event`.
     *
     * Keyed on source, not type: several sources (signup, milestone, renewal)
     * can share a reference_type, and a type-keyed lookup would treat two
     * genuinely different events as the same one. user_id is required because
     * one reference can legitimately produce rows for two different users
     * (a referral order earns both the advocate and the friend).
     *
     * Returns null for a NULL reference, because MySQL treats NULLs as distinct
     * in the unique index — a manual adjustment can never match an earlier one,
     * and asking is meaningless.
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

    /**
     * SUM(points) for a user — the ledger-side half of the balance invariant.
     *
     * Uncalled today; reserved for the future "Rebuild balances" tool, which
     * will replay the ledger to repair the customers cache.
     */
    public function sum_points_for_user( int $user_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COALESCE(SUM(points), 0) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d',
                $user_id
            )
        );
    }

    /**
     * The most recent entry for a user. Uncalled today: record() chains
     * balance_after off the locked customer row, not off this query.
     */
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
