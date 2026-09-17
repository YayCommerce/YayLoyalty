<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Per-campaign issuance reads over the ledger's campaign_id. Read-only
 * aggregate, not an AbstractRepository. These are real ids, while
 * GET /campaigns still serves dummy_fixture() ids, so they may not line up
 * on the Campaigns page until that fixture is removed.
 */
class CampaignPointsRepository {

    /**
     * Campaigns by points issued in the window, highest first.
     *
     * @return array<int, array{campaign_id: int, points: int}>
     */
    public function issued_by_campaign( string $from, string $to, int $limit = 3 ): array {
        global $wpdb;

        $in = $this->placeholders( $this->earn_types() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a "%s, %s" list; every value is bound.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT campaign_id, SUM(points) AS points FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE points > 0 AND campaign_id IS NOT NULL AND type IN ({$in}) AND created_at >= %s AND created_at < %s
                 GROUP BY campaign_id ORDER BY points DESC, campaign_id ASC LIMIT %d",
                array_merge( $this->earn_types(), [ $from, $to, $limit ] )
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

        $types_in = $this->placeholders( $this->earn_types() );
        $ids_in   = implode( ', ', array_fill( 0, count( $campaign_ids ), '%d' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $types_in / $ids_in are placeholder lists; every value is bound.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS date, campaign_id, SUM(points) AS issued
                 FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE points > 0 AND type IN ({$types_in}) AND campaign_id IN ({$ids_in}) AND created_at >= %s AND created_at < %s
                 GROUP BY 1, 2 ORDER BY 1, 2",
                array_merge( [ $offset_seconds ], $this->earn_types(), array_map( 'intval', $campaign_ids ), [ $from, $to ] )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return string[] Types that feed customers.lifetime_earned.
     */
    private function earn_types(): array {
        return array_values( array_filter( LedgerType::all(), [ LedgerType::class, 'affects_lifetime_earned' ] ) );
    }

    /**
     * @param string[] $values
     */
    private function placeholders( array $values ): string {
        return implode( ', ', array_fill( 0, count( $values ), '%s' ) );
    }
}
