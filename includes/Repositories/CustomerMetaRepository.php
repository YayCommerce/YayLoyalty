<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Models\CustomerMeta;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_customer_meta. Append-only: nothing here calls update() or
 * delete().
 *
 * `paginate_activity()`'s `search` never LIKEs the raw `meta_value` blob —
 * see `paginate_searched()` for why and how.
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

    /** COUNT(*) of a user's activity-log rows, over the full ActivityEvent vocabulary. */
    public function count_activity( int $user_id ): int {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Sql::in_placeholders() emits only a fixed-count %s list; values are bound via prepare() below.
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND meta_key IN (' . Sql::in_placeholders( ActivityEvent::ALL ) . ')',
                array_merge( [ $user_id ], ActivityEvent::ALL )
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

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Sql::in_placeholders() emits only a fixed-count %s list the sniff can't see statically; values are bound via prepare() below.
        $where   = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];
        $where[] = $wpdb->prepare(
            'meta_key IN (' . Sql::in_placeholders( $event_types ) . ')',
            $event_types
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $where = array_merge(
            $where,
            $this->site_day_range_where( (string) ( $args['after'] ?? '' ), (string) ( $args['before'] ?? '' ) )
        );

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

        return $this->paginate_searched( $where, $search, $page, $per_page );
    }

    /**
     * Activity rows matching $search in their real content, paginated in PHP.
     *
     * `search` never LIKEs the raw `meta_value` blob: every row carries the
     * literal structural keys `data`, `actor_kind`, `actor_name`,
     * `staff_only`, `schema_version`, so a raw LIKE would match almost every
     * row on metadata, not content. Candidate rows are instead fetched by
     * $where (user_id + meta_key IN (...) + date bounds, no LIMIT — one
     * customer's history, always small at this scale), decoded, filtered
     * against real content fields only, then paginated — `per_page` is
     * applied post-filter, not in SQL.
     *
     * @param string[] $where Already-prepared SQL fragments.
     * @return array{items: CustomerMeta[], total: int, page: int, per_page: int}
     */
    private function paginate_searched( array $where, string $search, int $page, int $per_page ): array {
        global $wpdb;

        $where_sql = ' WHERE ' . implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the fragments in $where are already prepared by paginate_activity().
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT * FROM `' . esc_sql( $this->table() ) . '`' . $where_sql . ' ORDER BY id DESC',
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $matches = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $item = CustomerMeta::from_row( $row );

            if ( self::activity_matches( $item, $search ) ) {
                $matches[] = $item;
            }
        }

        return [
            'items'    => array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ),
            'total'    => count( $matches ),
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /** Real content fields only: data's string/int values + actor_name — never the JSON envelope's structural keys. */
    private static function activity_matches( CustomerMeta $item, string $search ): bool {
        foreach ( $item->get_data() as $value ) {
            if ( ( is_string( $value ) || is_int( $value ) ) && false !== mb_stripos( (string) $value, $search ) ) {
                return true;
            }
        }

        $actor_name = $item->to_array()['actor_name'];

        return null !== $actor_name && false !== mb_stripos( (string) $actor_name, $search );
    }
}
