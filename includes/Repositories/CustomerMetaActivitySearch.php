<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\CustomerMeta;

defined( 'ABSPATH' ) || exit;

/**
 * The PHP-side search path `CustomerMetaRepository::paginate_activity()`
 * falls back to when `search` is non-empty — split out to keep that file
 * under the 200-line cap. `search` never LIKEs the raw `meta_value` blob:
 * every row carries the literal structural keys `data`, `actor_kind`,
 * `actor_name`, `staff_only`, `schema_version`, so a raw LIKE would match
 * almost every row on metadata, not content. Candidate rows are instead
 * fetched by the caller's already-prepared `WHERE` (user_id + meta_key
 * IN (...) + date bounds, no LIMIT — one customer's history, always small
 * at this scale), decoded here, filtered against real content fields only,
 * then paginated in PHP — `per_page` is applied post-filter, not in SQL.
 */
final class CustomerMetaActivitySearch {

    /**
     * @param string   $table Already `esc_sql()`'d table name.
     * @param string[] $where Already-prepared SQL fragments.
     * @return array{items: CustomerMeta[], total: int, page: int, per_page: int}
     */
    public static function paginate( string $table, array $where, string $search, int $page, int $per_page ): array {
        global $wpdb;

        $where_sql = ' WHERE ' . implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where fragments are already prepared by the caller; $table is escaped by the caller.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT * FROM `' . $table . '`' . $where_sql . ' ORDER BY id DESC',
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $matches = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $item = CustomerMeta::from_row( $row );

            if ( self::matches( $item, $search ) ) {
                $matches[] = $item;
            }
        }

        $total = count( $matches );

        return [
            'items'    => array_slice( $matches, ( $page - 1 ) * $per_page, $per_page ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /** Real content fields only: data's string/int values + actor_name — never the JSON envelope's structural keys. */
    private static function matches( CustomerMeta $item, string $search ): bool {
        foreach ( $item->get_data() as $value ) {
            if ( ( is_string( $value ) || is_int( $value ) ) && false !== mb_stripos( (string) $value, $search ) ) {
                return true;
            }
        }

        $actor_name = $item->to_array()['actor_name'];

        return null !== $actor_name && false !== mb_stripos( (string) $actor_name, $search );
    }
}
