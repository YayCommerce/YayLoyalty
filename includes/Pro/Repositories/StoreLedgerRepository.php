<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/** Every customer's ledger rows, newest first, with the drawer's filters plus customer search and campaign. */
class StoreLedgerRepository {

    private const MAX_PER_PAGE = 100;

    private LedgerRepository $ledger;

    private CustomerMetaRepository $customer_meta;

    public function __construct( LedgerRepository $ledger, ?CustomerMetaRepository $customer_meta = null ) {
        $this->ledger        = $ledger;
        $this->customer_meta = $customer_meta ?? new CustomerMetaRepository();
    }

    /**
     * Ordered by id, which the primary key serves; the table has no standalone created_at index.
     *
     * @param array{page?: int, per_page?: int, order?: string, types?: string[], search?: string, after?: string, before?: string, campaign_id?: int} $args
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate( array $args ): array {
        global $wpdb;

        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = max( 1, min( (int) ( $args['per_page'] ?? 20 ), self::MAX_PER_PAGE ) );
        $order    = 'asc' === strtolower( (string) ( $args['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';

        $where = $this->ledger->history_where(
            [
                'types'  => $args['types'] ?? [],
                'after'  => $args['after'] ?? '',
                'before' => $args['before'] ?? '',
            ]
        );

        $campaign_id = (int) ( $args['campaign_id'] ?? 0 );

        if ( $campaign_id > 0 ) {
            $where[] = $wpdb->prepare( 'l.campaign_id = %d', $campaign_id );
        }

        $search = trim( (string) ( $args['search'] ?? '' ) );

        if ( '' !== $search ) {
            $where[] = $this->search_fragment( $search );
        }

        $where_sql = empty( $where ) ? '' : ' WHERE ' . implode( ' AND ', $where );
        $table     = esc_sql( Table::name( 'ledger' ) );
        $join      = ' LEFT JOIN `' . esc_sql( $wpdb->users ) . '` u ON u.ID = l.user_id';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where fragments are each already prepared; identifiers are escaped; $order is whitelisted; only LIMIT/OFFSET are bound here.
        $total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            'SELECT COUNT(*) FROM `' . $table . '` l' . ( '' !== $search ? $join : '' ) . $where_sql
        );

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT l.*, u.display_name AS customer_name, u.user_email AS customer_email FROM `' . $table . '` l' . $join
                . $where_sql . " ORDER BY l.id {$order} LIMIT %d OFFSET %d",
                $per_page,
                ( $page - 1 ) * $per_page
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return [
            'items'    => array_map( [ $this, 'shape_row' ], is_array( $rows ) ? $rows : [] ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Row count per raw `LedgerType` over the whole store. Types with no rows are absent.
     *
     * @return array<string, int>
     */
    public function count_by_type(): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            'SELECT type, COUNT(*) AS row_count FROM `' . esc_sql( Table::name( 'ledger' ) ) . '` GROUP BY type', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no values; the identifier is escaped.
            ARRAY_A
        );

        $counts = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $counts[ (string) $row['type'] ] = (int) $row['row_count'];
        }

        return $counts;
    }

    /** Customer name or email, or the ledger's own note and order-number match. A deleted member is found by the latter only. */
    private function search_fragment( string $search ): string {
        global $wpdb;

        $like = '%' . $wpdb->esc_like( $search ) . '%';
        $own  = $this->ledger->history_where( [ 'search' => $search ] );

        return '(' . $wpdb->prepare( 'u.display_name LIKE %s OR u.user_email LIKE %s', $like, $like ) . ' OR ' . implode( ' AND ', $own ) . ')';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape_row( array $row ): array {
        $entry = LedgerEntry::from_row( $row )->to_array();

        // LEFT JOIN: a deleted member's rows stay listed, under the identity saved when the account went.
        if ( null === $row['customer_name'] ) {
            $identity = $this->customer_meta->deleted_identity( (int) $row['user_id'] );

            return $entry + [
                'customer_name'  => $identity['name'],
                'customer_email' => $identity['email'],
            ];
        }

        return $entry + [
            'customer_name'  => (string) $row['customer_name'],
            'customer_email' => (string) $row['customer_email'],
        ];
    }
}
