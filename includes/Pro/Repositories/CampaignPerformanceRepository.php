<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Utils\Sql;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * Real aggregates behind CampaignPerformanceRestController, replacing
 * CampaignPerformanceFixture. Every method scopes to one campaign_id with
 * the same `points > 0 AND type IN (earn types)` predicate
 * CampaignPointsRepository already uses, so a campaign's tiles and entries
 * table can never disagree with each other or with CampaignRestController's
 * own `points_issued` column (LedgerRepository::totals_by_campaign()).
 */
class CampaignPerformanceRepository {

    /**
     * Lifetime totals — no date bound; see the controller's own docblock
     * for why totals stay lifetime while the chart does not.
     *
     * @return array{points_issued: int, entries_total: int, orders_affected: int, customers_earned: int}
     */
    public function totals( int $campaign_id ): array {
        global $wpdb;

        $in = Sql::in_placeholders( LedgerType::lifetime_earned_types() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a "%s, %s" list; every value is bound.
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT COALESCE(SUM(points), 0) AS points_issued,
                        COUNT(*) AS entries_total,
                        COUNT(DISTINCT order_id) AS orders_affected,
                        COUNT(DISTINCT user_id) AS customers_earned
                 FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE campaign_id = %d AND points > 0 AND type IN ({$in})",
                array_merge( [ $campaign_id ], LedgerType::lifetime_earned_types() )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'points_issued'    => (int) ( $row['points_issued'] ?? 0 ),
            'entries_total'    => (int) ( $row['entries_total'] ?? 0 ),
            'orders_affected'  => (int) ( $row['orders_affected'] ?? 0 ),
            'customers_earned' => (int) ( $row['customers_earned'] ?? 0 ),
        ];
    }

    /**
     * Paged/searchable rows behind the entries table — orders, signups or
     * reviews depending on the campaign's trigger, told apart here by each
     * row's OWN reference_type rather than by re-deriving the campaign's
     * trigger, so this stays correct whatever reference type F01-4 ends up
     * writing for a signup award.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate_entries( int $campaign_id, int $page, int $per_page, string $search ): array {
        global $wpdb;

        $page     = max( 1, $page );
        $per_page = max( 1, $per_page );

        $in = Sql::in_placeholders( LedgerType::lifetime_earned_types() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$in} is a "%s, %s" placeholder list; every value is bound.
        $where = [
            $wpdb->prepare(
                "l.campaign_id = %d AND l.points > 0 AND l.type IN ({$in})",
                array_merge( [ $campaign_id ], LedgerType::lifetime_earned_types() )
            ),
        ];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $search = trim( $search );

        if ( '' !== $search ) {
            $where[] = $this->search_fragment( $search );
        }

        $where_sql = ' WHERE ' . implode( ' AND ', $where );
        $table     = esc_sql( Table::name( 'ledger' ) );
        $users     = esc_sql( $wpdb->users );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- $where fragments are each already prepared above; identifiers are escaped above; only LIMIT/OFFSET are bound here.
        $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'SELECT COUNT(*) FROM `' . $table . '` l LEFT JOIN `' . $users . '` u ON u.ID = l.user_id' . $where_sql
        );

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT l.id, l.user_id, u.display_name AS customer_name, l.points, l.created_at, l.reference_type, l.reference_id
                 FROM `' . $table . '` l LEFT JOIN `' . $users . '` u ON u.ID = l.user_id'
                . $where_sql . ' ORDER BY l.created_at DESC, l.id DESC LIMIT %d OFFSET %d',
                $per_page,
                ( $page - 1 ) * $per_page
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return [
            'items'    => array_map( [ $this, 'shape_entry' ], is_array( $rows ) ? $rows : [] ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Two needles, same guard CampaignPerformanceFixture::entries() used
     * (removed, see its git history): ltrim( $search, '#' ) on a search that
     * is ALL '#' characters reduces to '', and a LIKE against '%%' matches
     * every row — so the reference needle is dropped entirely once it goes
     * empty, rather than being allowed to match everything.
     */
    private function search_fragment( string $search ): string {
        global $wpdb;

        $name_like = '%' . $wpdb->esc_like( $search ) . '%';
        $ref       = ltrim( $search, '#' );

        if ( '' === $ref ) {
            return $wpdb->prepare( 'u.display_name LIKE %s', $name_like );
        }

        $ref_like = '%' . $wpdb->esc_like( $ref ) . '%';

        return $wpdb->prepare( '(u.display_name LIKE %s OR CAST(l.reference_id AS CHAR) LIKE %s)', $name_like, $ref_like );
    }

    /**
     * One ledger row into campaignPerformanceEntrySchema's shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape_entry( array $row ): array {
        $reference_type = $row['reference_type'];
        $reference_id   = null !== $row['reference_id'] ? (int) $row['reference_id'] : null;

        return [
            'id'            => (int) $row['id'],
            'customer_id'   => (int) $row['user_id'],
            // LEFT JOIN: a deleted member's rows stay in totals(), so they stay listed too.
            'customer_name' => null !== $row['customer_name']
                ? (string) $row['customer_name']
                /* translators: %d: the deleted customer's former user id. */
                : sprintf( __( 'Deleted customer #%d', 'yayloyalty' ), (int) $row['user_id'] ),
            'points'        => (int) $row['points'],
            'created_at'    => (string) $row['created_at'],
            'reference'     => $this->reference( $reference_type, $reference_id ),
            'subtotal'      => ReferenceType::ORDER === $reference_type && null !== $reference_id
                ? $this->order_subtotal( $reference_id )
                : null,
        ];
    }

    /**
     * @return array{id: int, label: string, url: string}|null
     */
    private function reference( ?string $type, ?int $id ): ?array {
        if ( null === $id ) {
            return null;
        }

        switch ( $type ) {
            case ReferenceType::ORDER:
                return [
                    'id'    => $id,
                    'label' => '#' . $id,
                    'url'   => $this->order_edit_url( $id ),
                ];
            case ReferenceType::COMMENT:
                return [
                    'id'    => $id,
                    'label' => '#' . $id,
                    'url'   => admin_url( 'comment.php?action=editcomment&c=' . $id ),
                ];
            default:
                // Signup (ReferenceType::USER) has no secondary record to link to.
                return null;
        }
    }

    /** Null rather than a broken link when WooCommerce no longer has the order (deleted). */
    private function order_subtotal( int $order_id ): ?float {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return null;
        }

        $order = wc_get_order( $order_id );

        return $order instanceof \WC_Order ? (float) $order->get_subtotal() : null;
    }

    /** HPOS-safe order edit URL — same detection DashboardNotices::orders_admin_url() uses for its own (list) URL. */
    private function order_edit_url( int $order_id ): string {
        $hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        return $hpos
            ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
            : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
    }
}
