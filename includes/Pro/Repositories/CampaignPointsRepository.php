<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Sql;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Points issued per campaign, read from the ledger for the Pro dashboard and the performance chart.
 * A read-only aggregate, so not an AbstractRepository. LedgerRepository::totals_by_campaign() counts
 * rows for the delete guard; this ranks campaigns by SUM(points) alone.
 */
class CampaignPointsRepository {

    /**
     * Campaigns by points issued in the window, highest first.
     *
     * @return array<int, array{campaign_id: int, points: int}>
     */
    public function issued_by_campaign( string $from, string $to, int $limit = 3 ): array {
        global $wpdb;

        list( $issued, $types ) = LedgerRepository::issued_row_sql();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $issued carries a "%s, %s" list; every value is bound.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT campaign_id, SUM(points) AS points FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE {$issued} AND campaign_id IS NOT NULL AND created_at >= %s AND created_at < %s
                 GROUP BY campaign_id ORDER BY points DESC, campaign_id ASC LIMIT %d",
                array_merge( $types, [ $from, $to, $limit ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return array_map(
            static fn( array $row ): array => [
                'campaign_id' => (int) $row['campaign_id'],
                'points'      => (int) $row['points'],
            ],
            is_array( $rows ) ? $rows : []
        );
    }

    /**
     * Points issued per site-timezone day per campaign; only pairs with activity, the caller zero-fills.
     *
     * @param int[] $campaign_ids
     * @return array<int, array{date: string, campaign_id: string, issued: string}>
     */
    public function daily_by_campaign( string $from, string $to, int $offset_seconds, array $campaign_ids ): array {
        global $wpdb;

        if ( empty( $campaign_ids ) ) {
            return [];
        }

        list( $issued, $types ) = LedgerRepository::issued_row_sql();
        $ids_in                 = Sql::in_placeholders( $campaign_ids, '%d' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $issued / $ids_in carry placeholder lists; every value is bound.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS date, campaign_id, SUM(points) AS issued
                 FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE {$issued} AND campaign_id IN ({$ids_in}) AND created_at >= %s AND created_at < %s
                 GROUP BY 1, 2 ORDER BY 1, 2",
                array_merge( [ $offset_seconds ], $types, array_map( 'intval', $campaign_ids ), [ $from, $to ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return is_array( $rows ) ? $rows : [];
    }
}
