<?php
namespace YayLoyalty\Pro\Repositories;

use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/** The admin's referral list: every referral with the advocate's and friend's names, filtered, searched and counted. */
final class ReferralListRepository {

    private const MAX_PER_PAGE = 100;

    private CustomerMetaRepository $customer_meta;

    public function __construct( ?CustomerMetaRepository $customer_meta = null ) {
        $this->customer_meta = $customer_meta ?? new CustomerMetaRepository();
    }

    /**
     * Newest first, or by hold end (oldest first) for the Approvals queue.
     *
     * @param array{page?: int, per_page?: int, status?: string, search?: string, orderby?: string} $args
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function paginate( array $args ): array {
        global $wpdb;

        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = max( 1, min( (int) ( $args['per_page'] ?? 20 ), self::MAX_PER_PAGE ) );
        $where    = [];
        $status   = (string) ( $args['status'] ?? '' );
        $search   = trim( (string) ( $args['search'] ?? '' ) );

        if ( '' !== $status ) {
            $where[] = $wpdb->prepare( 'r.status = %s', $status );
        }

        if ( '' !== $search ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = $wpdb->prepare( '(a.display_name LIKE %s OR a.user_email LIKE %s OR f.display_name LIKE %s OR f.user_email LIKE %s)', $like, $like, $like, $like );
        }

        $where_sql = [] === $where ? '' : ' WHERE ' . implode( ' AND ', $where );
        $order_sql = 'hold_until' === ( $args['orderby'] ?? '' ) ? 'r.hold_until ASC, r.id ASC' : 'r.id DESC';
        $from      = ' FROM `' . esc_sql( Table::name( 'referrals' ) ) . '` r'
            . ' LEFT JOIN `' . esc_sql( $wpdb->users ) . '` a ON a.ID = r.advocate_user_id'
            . ' LEFT JOIN `' . esc_sql( $wpdb->users ) . '` f ON f.ID = r.friend_user_id';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- each $where fragment is prepared; identifiers are escaped; $order_sql is one of two fixed strings; only LIMIT/OFFSET are bound here.
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $from . $where_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
        $rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT r.*, a.display_name AS advocate_name, a.user_email AS advocate_email, f.display_name AS friend_name, f.user_email AS friend_email'
                . $from . $where_sql . " ORDER BY {$order_sql} LIMIT %d OFFSET %d",
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
     * Referrals per status over the whole store. Statuses with none are absent.
     *
     * @return array<string, int>
     */
    public function count_by_status(): array {
        global $wpdb;

        $rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            'SELECT status, COUNT(*) AS row_count FROM `' . esc_sql( Table::name( 'referrals' ) ) . '` GROUP BY status', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no values; the identifier is escaped.
            ARRAY_A
        );
        $counts = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $counts[ (string) $row['status'] ] = (int) $row['row_count'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape_row( array $row ): array {
        $referral = Referral::from_row( $row );

        return $referral->to_array() + [
            'advocate' => $this->person( $referral->get_advocate_user_id(), $row['advocate_name'] ?? null, $row['advocate_email'] ?? null ),
            'friend'   => $this->person( $referral->get_friend_user_id(), $row['friend_name'] ?? null, $row['friend_email'] ?? null ),
        ];
    }

    /**
     * The person as the users table has them, or as saved when their account was deleted.
     *
     * @param mixed $name
     * @param mixed $email
     * @return array{id: int, name: string, email: string}
     */
    private function person( int $user_id, $name, $email ): array {
        if ( null === $name ) {
            $identity = $this->customer_meta->deleted_identity( $user_id );
            $name     = $identity['name'];
            $email    = $identity['email'];
        }

        return [
            'id'    => $user_id,
            'name'  => (string) $name,
            'email' => (string) $email,
        ];
    }
}
