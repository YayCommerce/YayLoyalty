<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Models\CustomerMeta;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_customer_meta. Append-only: nothing here calls update() or delete(). */
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

    /** Most recent row for (user_id, meta_key), such as the account_deleted snapshot. */
    public function latest( int $user_id, string $meta_key ): ?CustomerMeta {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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

    /**
     * A deleted member's name and email from their account_deleted snapshot, or '' for each.
     * Every screen that lists a deleted member uses it, so the name matches everywhere.
     *
     * @return array{name: string, email: string}
     */
    public function deleted_identity( int $user_id ): array {
        $deletion = $this->latest( $user_id, ActivityEvent::ACCOUNT_DELETED );

        return null !== $deletion ? $deletion->snapshot_identity() : [
            'name'  => '',
            'email' => '',
        ];
    }

    /** Whether at least one row exists for (user_id, meta_key). It is the once-only write guard. */
    public function has( int $user_id, string $meta_key ): bool {
        global $wpdb;

        $id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND meta_key IN (' . Sql::in_placeholders( ActivityEvent::ALL ) . ')',
                array_merge( [ $user_id ], ActivityEvent::ALL )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * One customer's activity log, filtered by group, search and date range, and paginated.
     * An empty $event_types returns an empty page before any SQL is built, so `IN ()` is never reached.
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
     * `search` never LIKEs the raw `meta_value` blob: every row carries the structural keys `data`,
     * `actor_kind`, `actor_name`, `staff_only` and `schema_version`, so a raw LIKE would match almost
     * every row. Instead this fetches every row $where selects, with no LIMIT, since one customer's
     * history is small. It decodes them, filters on content fields only, then applies `per_page`.
     *
     * @param string[] $where Already-prepared SQL fragments.
     * @return array{items: CustomerMeta[], total: int, page: int, per_page: int}
     */
    private function paginate_searched( array $where, string $search, int $page, int $per_page ): array {
        global $wpdb;

        $where_sql = ' WHERE ' . implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the fragments in $where are already prepared by paginate_activity().
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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

    /** Real content fields only: data's string and int values plus actor_name, never the envelope's structural keys. */
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
