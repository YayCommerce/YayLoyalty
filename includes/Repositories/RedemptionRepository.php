<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Models\Redemption;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Sql;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_redemptions. Only Engine\Redemption\CommitWriter inserts rows,
 * under the customer's row lock. The commit's duplicate check, the
 * `redemption_count` reward condition, the customer drawer and the rewards
 * list's claim summary read them. Scaffold: stubs except summary_by_rewards().
 * The table is Schema\RedemptionsTable, registered in Installer::schemas()
 * when redemption ships.
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
     * The double-debit guard's read: the flexible redemption already
     * written for this order, if any. Postcondition: at most one row
     * (UNIQUE (order_id, reward_id) on the table); null when none.
     */
    public function find_by_order( int $order_id, int $reward_id ): ?Redemption { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): SELECT * WHERE order_id = %d AND reward_id = %d LIMIT 1, hydrated.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * How many times this customer has redeemed this reward, for the
     * `redemption_count` condition. Postcondition: COUNT(*) over the
     * (reward_id, user_id) index, statuses other than cancelled.
     */
    public function count_for_customer( int $reward_id, int $user_id ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): SELECT COUNT(*) WHERE reward_id = %d AND user_id = %d AND status <> 'cancelled'.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * A reward's summary when no redemption row names it; the one spelling of the shape.
     *
     * @return array{claimed: int, outstanding: int, outstanding_customers: int, outstanding_points: int, first_claimed_at: string|null, last_expires_at: string|null}
     */
    public static function empty_summary(): array {
        return [
            'claimed'               => 0,
            'outstanding'           => 0,
            'outstanding_customers' => 0,
            'outstanding_points'    => 0,
            'first_claimed_at'      => null,
            'last_expires_at'       => null,
        ];
    }

    /**
     * Claim figures per reward, for the rewards list and its archive/delete
     * dialog. `claimed` counts every row in any status: the delete guard needs
     * "has any claim ever named this reward", which a points or status filter
     * cannot answer. "Outstanding" is an issued coupon that has not expired.
     * Absent from the result for an id with no rows, and `[]` while the table
     * is not installed — true then, since no claim can exist.
     *
     * @param int[] $reward_ids
     * @return array<int, array{claimed: int, outstanding: int, outstanding_customers: int, outstanding_points: int, first_claimed_at: string|null, last_expires_at: string|null}>
     */
    public function summary_by_rewards( array $reward_ids ): array {
        if ( empty( $reward_ids ) || ! $this->table_exists() ) {
            return [];
        }

        global $wpdb;

        $ids         = array_map( 'intval', $reward_ids );
        $in          = Sql::in_placeholders( $ids, '%d' );
        $outstanding = 'status = %s AND (expires_at IS NULL OR expires_at > %s)';
        $bind        = [ Redemption::STATUS_ISSUED, Dates::now() ];

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in and $outstanding carry placeholders; every value is bound below.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT reward_id,
                        COUNT(*) AS claimed,
                        COALESCE(SUM(CASE WHEN {$outstanding} THEN 1 ELSE 0 END), 0) AS outstanding,
                        COUNT(DISTINCT CASE WHEN {$outstanding} THEN user_id END) AS outstanding_customers,
                        COALESCE(SUM(CASE WHEN {$outstanding} THEN points ELSE 0 END), 0) AS outstanding_points,
                        MIN(created_at) AS first_claimed_at,
                        MAX(CASE WHEN {$outstanding} THEN expires_at END) AS last_expires_at
                 FROM `" . esc_sql( $this->table() ) . "`
                 WHERE reward_id IN ({$in})
                 GROUP BY reward_id",
                array_merge( $bind, $bind, $bind, $bind, $ids )
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
