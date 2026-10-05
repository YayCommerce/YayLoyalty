<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Table;
use YayLoyalty\Utils\WooOrders;

defined( 'ABSPATH' ) || exit;

/**
 * The aggregates behind CampaignPerformanceRestController. Every query uses LedgerRepository::issued_row_sql(),
 * the one definition of an issued row, so a campaign's tiles, its entries and the campaign list never disagree.
 */
class CampaignPerformanceRepository {

    private CustomerMetaRepository $customer_meta;

    public function __construct( ?CustomerMetaRepository $customer_meta = null ) {
        $this->customer_meta = $customer_meta ?? new CustomerMetaRepository();
    }

    /**
     * Lifetime totals, with no date bound.
     *
     * @return array{points_issued: int, entries_total: int, orders_affected: int, customers_earned: int}
     */
    public function totals( int $campaign_id ): array {
        global $wpdb;

        list( $issued, $types ) = LedgerRepository::issued_row_sql();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $issued carries a "%s, %s" list; every value is bound.
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COALESCE(SUM(points), 0) AS points_issued,
                        COUNT(*) AS entries_total,
                        COUNT(DISTINCT order_id) AS orders_affected,
                        COUNT(DISTINCT user_id) AS customers_earned
                 FROM `' . esc_sql( Table::name( 'ledger' ) ) . "`
                 WHERE campaign_id = %d AND {$issued}",
                array_merge( [ $campaign_id ], $types )
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
     * Paged, searchable entries. Rows are told apart by their own reference_type, not by the campaign's
     * trigger, so each trigger handler's reference_type() stays the one mapping.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate_entries( int $campaign_id, int $page, int $per_page, string $search ): array {
        global $wpdb;

        $page     = max( 1, $page );
        $per_page = max( 1, $per_page );

        list( $issued, $types ) = LedgerRepository::issued_row_sql( 'l.' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $issued carries a "%s, %s" placeholder list; every value is bound.
        $where = [
            $wpdb->prepare( "l.campaign_id = %d AND {$issued}", array_merge( [ $campaign_id ], $types ) ),
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
        $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            'SELECT COUNT(*) FROM `' . $table . '` l LEFT JOIN `' . $users . '` u ON u.ID = l.user_id' . $where_sql
        );

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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
     * A search of only '#' leaves an empty reference needle, which would match every row, so it is dropped.
     * The name needle reads the joined WordPress user, so a deleted member is found by reference id only.
     */
    private function search_fragment( string $search ): string {
        global $wpdb;

        $name_like = '%' . $wpdb->esc_like( $search ) . '%';
        $reference = ltrim( $search, '#' );

        if ( '' === $reference ) {
            return $wpdb->prepare( 'u.display_name LIKE %s', $name_like );
        }

        $reference_like = '%' . $wpdb->esc_like( $reference ) . '%';

        return $wpdb->prepare( '(u.display_name LIKE %s OR CAST(l.reference_id AS CHAR) LIKE %s)', $name_like, $reference_like );
    }

    /**
     * One ledger row in the entries' wire shape.
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
            'customer_name' => null !== $row['customer_name'] ? (string) $row['customer_name'] : $this->deleted_name( (int) $row['user_id'] ),
            'points'        => (int) $row['points'],
            'created_at'    => (string) $row['created_at'],
            'reference'     => $this->reference( $reference_type, $reference_id ),
            'subtotal'      => ReferenceType::ORDER === $reference_type && null !== $reference_id
                ? $this->order_subtotal( $reference_id )
                : null,
        ];
    }

    /**
     * A deleted member's name from their account_deleted snapshot, or ''. The customer list and drawer use the
     * same fallback (CustomerListFormatter::identity(), CustomerItemFormatter::identity()), so the name matches everywhere.
     */
    private function deleted_name( int $user_id ): string {
        $deletion = $this->customer_meta->latest( $user_id, ActivityEvent::ACCOUNT_DELETED );

        return null !== $deletion ? $deletion->snapshot_identity()['name'] : '';
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

    /** Null when WooCommerce no longer has the order. */
    private function order_subtotal( int $order_id ): ?float {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return null;
        }

        $order = wc_get_order( $order_id );

        return $order instanceof \WC_Order ? (float) $order->get_subtotal() : null;
    }

    private function order_edit_url( int $order_id ): string {
        return WooOrders::is_hpos()
            ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id )
            : admin_url( 'post.php?post=' . $order_id . '&action=edit' );
    }
}
