<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_lots and its allocations. A take decrements a lot and writes the allocation that explains
 * it, so the two tables share one repository. Rows are plain arrays: nothing outside LotBook reads them.
 * Callers hold the customer's row lock (LedgerService), which serialises each user's lots, so reads
 * here do not lock: a locking read over a user with no lots gap-locks, and two customers' first lots
 * would deadlock. take()'s guarded UPDATE still refuses a lot that changed under a stale read.
 */
class LotRepository {

    public function table(): string {
        return Table::name( 'lots' );
    }

    public function allocations_table(): string {
        return Table::name( 'lot_allocations' );
    }

    /**
     * @return int The new lot's id.
     * @throws QueryException When the write fails.
     */
    public function open( int $ledger_id, int $user_id, int $points, int $remaining, string $created_at, ?string $expires_at ): int {
        global $wpdb;

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $this->table(),
            [
                'ledger_id'  => $ledger_id,
                'user_id'    => $user_id,
                'points'     => $points,
                'remaining'  => $remaining,
                'expires_at' => $expires_at,
                'status'     => LotStatus::OPEN,
                'created_at' => $created_at,
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s' ]
        );

        if ( false === $result ) {
            throw new QueryException( 'insert', $wpdb->last_error, $this->table() );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * A user's open lots in spend order.
     *
     * @param string $order 'ASC' for oldest first, 'DESC' for newest first, by created_at then id.
     * @return array<int, array{id: int, ledger_id: int, remaining: int, expires_at: ?string}>
     */
    public function open_lots( int $user_id, string $order ): array {
        global $wpdb;

        $direction = 'DESC' === $order ? 'DESC' : 'ASC';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $direction is one of two literals.
        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT id, ledger_id, remaining, expires_at FROM `' . esc_sql( $this->table() ) . "`
                 WHERE user_id = %d AND status = %s ORDER BY created_at {$direction}, id {$direction}",
                $user_id,
                LotStatus::OPEN
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array_map(
            static function ( array $row ): array {
                return [
                    'id'         => (int) $row['id'],
                    'ledger_id'  => (int) $row['ledger_id'],
                    'remaining'  => (int) $row['remaining'],
                    'expires_at' => null === $row['expires_at'] ? null : (string) $row['expires_at'],
                ];
            },
            is_array( $rows ) ? $rows : []
        );
    }

    /**
     * Takes $points from one open lot and records the allocation. A lot that reaches zero gets $empty_status.
     *
     * @throws \RuntimeException When the lot is not open or holds fewer than $points: a bug, never a clamp.
     * @throws QueryException When a write fails.
     */
    public function take( int $lot_id, int $points, int $ledger_id, string $empty_status, string $at ): void {
        global $wpdb;

        // status first: MySQL applies SET left to right, so after the decrement `remaining` is already the new value.
        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'UPDATE `' . esc_sql( $this->table() ) . '`
                 SET status = IF(remaining = %d, %s, status), remaining = remaining - %d
                 WHERE id = %d AND status = %s AND remaining >= %d',
                $points,
                $empty_status,
                $points,
                $lot_id,
                LotStatus::OPEN,
                $points
            )
        );

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (take)' );
        }

        if ( 1 !== (int) $result ) {
            throw new \RuntimeException( sprintf( 'YayLoyalty: lot %d cannot give %d points.', $lot_id, $points ) );
        }

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $this->allocations_table(),
            [
                'lot_id'     => $lot_id,
                'ledger_id'  => $ledger_id,
                'points'     => $points,
                'created_at' => $at,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        if ( false === $result ) {
            throw new QueryException( 'insert', $wpdb->last_error, $this->allocations_table() );
        }
    }

    /** Whether the user has any lot, open or not: one that does has been backfilled. */
    public function has_any( int $user_id ): bool {
        global $wpdb;

        return null !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT 1 FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d LIMIT 1',
                $user_id
            )
        );
    }

    /**
     * Customers with a positive balance and no lot yet, for the backfill.
     *
     * @return int[]
     */
    public function user_ids_needing_backfill( int $limit ): array {
        global $wpdb;

        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT c.user_id FROM `' . esc_sql( Table::name( 'customers' ) ) . '` c
                 LEFT JOIN `' . esc_sql( $this->table() ) . '` l ON l.user_id = c.user_id
                 WHERE c.balance > 0 AND l.id IS NULL LIMIT %d',
                $limit
            )
        );

        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }
}
