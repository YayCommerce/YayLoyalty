<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\CustomerMeta;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_customer_meta. Append-only: nothing here calls update() or
 * delete().
 *
 * `paginate_activity()`'s `search` never LIKEs the raw `meta_value` blob:
 * every row carries the literal structural keys `data`, `actor_kind`,
 * `actor_name`, `staff_only`, `schema_version`, so a raw LIKE would match
 * almost every row on metadata, not content. Candidate rows are instead
 * fetched by `user_id` + `meta_key IN (...)` + date bounds, decoded in PHP,
 * filtered against real content fields only, then paginated in PHP —
 * `per_page` is applied post-filter in that path, not in SQL. Safe only
 * because every query here is already scoped to one customer's small row
 * set (docs/data-model.md), never a cross-customer search.
 *
 * Not final: CustomerActivityServiceTest extends it as a repository double
 * (mirrors DashboardRepository/CampaignRepository, the same reason those
 * two aren't final either).
 */
class CustomerMetaRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'customer_meta';
    }

    protected function model_class(): string {
        return CustomerMeta::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'created_at', 'meta_key' ];
    }

    /** Most recent row for (user_id, meta_key) — e.g. the account_deleted snapshot. */
    public function latest( int $user_id, string $meta_key ): ?CustomerMeta {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1',
                $user_id,
                $meta_key
            ),
            ARRAY_A
        );

        return is_array( $row ) ? CustomerMeta::from_row( $row ) : null;
    }

    /** Whether at least one row exists for (user_id, meta_key) — the once-only write guard. */
    public function has( int $user_id, string $meta_key ): bool {
        global $wpdb;

        $id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT id FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND meta_key = %s LIMIT 1',
                $user_id,
                $meta_key
            )
        );

        return null !== $id;
    }

    /**
     * COUNT(*) of rows matching user_id + one of $event_types. An empty
     * list short-circuits to 0 before building SQL: `IN ()` is invalid.
     *
     * @param string[] $event_types
     */
    public function count_activity( int $user_id, array $event_types ): int {
        if ( empty( $event_types ) ) {
            return 0;
        }

        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- build_in_placeholders() emits only a fixed-count %s list; values are bound via prepare() below.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND meta_key IN (' . $this->build_in_placeholders( $event_types ) . ')',
                array_merge( [ $user_id ], $event_types )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * One customer's activity log: filtered (group/search/date-range) and
     * paginated. An empty $event_types short-circuits to an empty page
     * before building SQL — never reaches `IN ()`.
     *
     * @param array{event_types: string[], search?: string, after?: string, before?: string, page?: int, per_page?: int} $args
     * @return array{items: CustomerMeta[], total: int, page: int, per_page: int}
     */
    public function paginate_activity( int $user_id, array $args = [] ): array {
        $event_types = $args['event_types'] ?? [];
        $page        = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page    = max( 1, min( (int) ( $args['per_page'] ?? 20 ), self::MAX_PER_PAGE ) );

        if ( empty( $event_types ) ) {
            return [
                'items'    => [],
                'total'    => 0,
                'page'     => $page,
                'per_page' => $per_page,
            ];
        }

        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- build_in_placeholders() emits only a fixed-count %s list the sniff can't see statically; values are bound via prepare() below.
        $where   = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];
        $where[] = $wpdb->prepare(
            'meta_key IN (' . $this->build_in_placeholders( $event_types ) . ')',
            $event_types
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $after  = trim( (string) ( $args['after'] ?? '' ) );
        $before = trim( (string) ( $args['before'] ?? '' ) );

        if ( '' !== $after ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', $after . ' 00:00:00' );
        }

        if ( '' !== $before ) {
            $where[] = $wpdb->prepare( 'created_at <= %s', $before . ' 23:59:59' );
        }

        $search = trim( (string) ( $args['search'] ?? '' ) );

        if ( '' === $search ) {
            return $this->paginate(
                [
                    'where'    => $where,
                    'page'     => $page,
                    'per_page' => $per_page,
                    'orderby'  => 'id',
                    'order'    => 'desc',
                ]
            );
        }

        return CustomerMetaActivitySearch::paginate( esc_sql( $this->table() ), $where, $search, $page, $per_page );
    }

    /**
     * Pure `IN (...)` placeholder fragment: no `$wpdb`, no query building.
     * Extracted so the bug class this guards against (a miscounted
     * placeholder list vs bound values) is unit-testable without WordPress
     * (tests/unit/Repositories/CustomerMetaRepositoryPlaceholdersTest.php).
     *
     * @param array $values
     */
    private function build_in_placeholders( array $values ): string {
        return implode( ',', array_fill( 0, count( $values ), '%s' ) );
    }
}
