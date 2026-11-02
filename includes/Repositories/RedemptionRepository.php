<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\Redemption;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_redemptions. Only Engine\Redemption\CommitWriter inserts rows,
 * under the customer's row lock; Engine\Redemption\Reversal moves only `status` and `restored_points`.
 * The commit's duplicate check, the customer drawer, the rewards list's claim summary,
 * the loyalty-code guard on orders and the order screen's remove dialog read them. The table is Schema\RedemptionsTable.
 */
final class RedemptionRepository extends AbstractRepository {

    protected function table_slug(): string {
        return 'redemptions';
    }

    protected function model_class(): string {
        return Redemption::class;
    }

    protected function sortable_columns(): array {
        return [ 'id', 'created_at', 'status', 'points' ];
    }

    public function find_redemption( int $id ): ?Redemption {
        $found = $this->find( $id );

        return $found instanceof Redemption ? $found : null;
    }

    /**
     * The flexible discount redemption already written for this order, oldest first, or
     * null. No unique index backs it: the caller holds the customer lock.
     */
    public function find_by_order( int $order_id, int $reward_id ): ?Redemption {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE order_id = %d AND reward_id = %d ORDER BY id ASC LIMIT 1',
                $order_id,
                $reward_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? Redemption::from_row( $row ) : null;
    }

    /**
     * Every redemption on an order, oldest first. A plain read: Reversal
     * takes only ids and user ids from it, then lock()s each row;
     * CommitWriter matches rows to the order's coupon codes under the
     * customer lock.
     *
     * @return Redemption[]
     */
    public function for_order( int $order_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE order_id = %d ORDER BY id ASC',
                $order_id
            ),
            ARRAY_A
        );

        return array_map( [ Redemption::class, 'from_row' ], is_array( $rows ) ? $rows : [] );
    }

    /**
     * The order's row for the coupon line $code, in any status, or null. Codes compare under
     * wc_format_coupon_code(), as WooCommerce matches coupon lines.
     */
    public function find_for_order_code( int $order_id, string $code ): ?Redemption {
        $code = wc_format_coupon_code( $code );

        foreach ( $this->for_order( $order_id ) as $row ) {
            if ( null !== $row->get_coupon_code() && wc_format_coupon_code( $row->get_coupon_code() ) === $code ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Locks one row and returns it, or null when it is gone. Call inside a
     * transaction, after the customer lock. A locking read, so it returns
     * the row as last committed even when the transaction's snapshot is older.
     */
    public function lock( int $id ): ?Redemption {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a locking read must reach the database, never a cache.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( $this->table() ) . '` WHERE id = %d FOR UPDATE',
                $id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? Redemption::from_row( $row ) : null;
    }

    /**
     * Moves the two columns a reversal owns. Precondition: the caller holds lock() on the row,
     * $restored_points is within 0 and the row's points, and $status is `refunded` only when they are equal.
     *
     * @throws \YayLoyalty\Database\QueryException When the update fails.
     */
    public function record_restore( int $id, int $restored_points, string $status ): void {
        $this->update(
            $id,
            [
                'restored_points' => $restored_points,
                'status'          => $status,
                'updated_at'      => Dates::now(),
            ],
            [ '%d', '%s', '%s' ]
        );
    }

    /**
     * One page of a customer's redemptions, for the drawer.
     *
     * @param array $args See AbstractRepository::paginate().
     * @return array{items: Redemption[], total: int, page: int, per_page: int}
     */
    public function paginate_for_user( int $user_id, array $args = [] ): array {
        global $wpdb;

        $args['where'] = [ $wpdb->prepare( 'user_id = %d', $user_id ) ];

        if ( ! isset( $args['orderby'] ) ) {
            $args['orderby'] = 'id';
        }

        return $this->paginate( $args );
    }

    /** COUNT(*) of a customer's redemption rows, in any status. */
    public function count_for_user( int $user_id ): int {
        return $this->count_where_user( $user_id );
    }

    /**
     * A reward's summary when no redemption row names it; the one spelling of the shape.
     *
     * @return array{claimed: int, first_claimed_at: string|null}
     */
    public static function empty_summary(): array {
        return [
            'claimed'          => 0,
            'first_claimed_at' => null,
        ];
    }

    /**
     * Claim figures per reward, for the rewards list and its archive/delete
     * dialog. `claimed` counts every row in any status: the delete guard needs
     * "has any claim ever named this reward", which a points or status filter
     * cannot answer. Absent from the result for an id with no rows, and `[]`
     * while the table is not installed — true then, since no claim can exist.
     *
     * @param int[] $reward_ids
     * @return array<int, array{claimed: int, first_claimed_at: string|null}>
     */
    public function summary_by_rewards( array $reward_ids ): array {
        if ( empty( $reward_ids ) || ! $this->table_exists() ) {
            return [];
        }

        global $wpdb;

        $ids = array_map( 'intval', $reward_ids );
        $in  = Sql::in_placeholders( $ids, '%d' );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in carries placeholders; every value is bound below.
        $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT reward_id,
                        COUNT(*) AS claimed,
                        MIN(created_at) AS first_claimed_at
                 FROM `' . esc_sql( $this->table() ) . "`
                 WHERE reward_id IN ({$in})
                 GROUP BY reward_id",
                $ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $summaries = [];

        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $summary = self::empty_summary();

            foreach ( $summary as $key => $default ) {
                $value           = $row[ $key ] ?? null;
                $summary[ $key ] = null === $default ? $value : (int) $value;
            }

            $summaries[ (int) $row['reward_id'] ] = $summary;
        }

        return $summaries;
    }

    /** Asked on every call, never memoised: the table appears mid-life when the redemption upgrade runs, and multisite switches blogs. */
    private function table_exists(): bool {
        global $wpdb;

        $table = $this->table();

        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }
}
