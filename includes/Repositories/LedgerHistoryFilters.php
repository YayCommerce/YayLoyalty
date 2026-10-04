<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/**
 * Prepared WHERE fragments for `LedgerRepository::paginate_for_user()`'s
 * filters — split out to keep that file near the 200-line cap.
 *
 * Row descriptions ("Earned on order #6730") are composed client-side, so
 * search can only reach what is stored: a bare or `#`-prefixed number is
 * also an exact `order_id`, and any text matches the admin's `note`.
 */
final class LedgerHistoryFilters {

    /**
     * @param array{types?: string[], search?: string, after?: string, before?: string} $args
     *        `after`/`before` are site-calendar `Y-m-d` days, both inclusive.
     * @return string[] Already-prepared fragments, for AbstractRepository::paginate()'s `where`.
     */
    public static function where( array $args ): array {
        global $wpdb;

        $where = [];
        $types = $args['types'] ?? [];

        if ( ! empty( $types ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholder list only; values bound here.
            $where[] = $wpdb->prepare( 'type IN (' . Sql::in_placeholders( $types ) . ')', $types );
        }

        $where = array_merge(
            $where,
            AbstractRepository::site_day_range_where( $args['after'] ?? '', $args['before'] ?? '' )
        );

        $search = trim( $args['search'] ?? '' );

        if ( '' !== $search ) {
            $note_like_sql = AbstractRepository::like_fragment( 'note', $search );
            $order_number  = ltrim( $search, '#' );

            $where[] = ctype_digit( $order_number )
                ? '(' . $wpdb->prepare( 'order_id = %d', (int) $order_number ) . ' OR ' . $note_like_sql . ')'
                : $note_like_sql;
        }

        return $where;
    }
}
