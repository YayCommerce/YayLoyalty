<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_lots and its allocations. A take decrements a lot and writes the allocation that explains
 * it, so the two tables share one repository. Rows are plain arrays: nothing outside LotService reads them.
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
     * A user's open lots whose expiry has passed at $now, oldest first.
     *
     * @return array<int, array{id: int, ledger_id: int, remaining: int, expires_at: ?string}>
     */
    public function due_lots( int $user_id, string $now ): array {
        return array_values(
            array_filter(
                $this->open_lots( $user_id, 'ASC' ),
                static function ( array $lot ) use ( $now ): bool {
                    return null !== $lot['expires_at'] && strcmp( $lot['expires_at'], $now ) <= 0;
                }
            )
        );
    }

    /**
     * Customers with an open lot past its expiry at $now, for the batch expiry run.
     *
     * @return int[]
     */
    public function due_user_ids( string $now, int $limit ): array {
        global $wpdb;

        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT DISTINCT user_id FROM `' . esc_sql( $this->table() ) . '` WHERE status = %s AND expires_at <= %s LIMIT %d',
                LotStatus::OPEN,
                $now,
                $limit
            )
        );

        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }

    /** The soonest expiry among a user's open lots, or null. */
    public function earliest_open_expiry( int $user_id ): ?string {
        global $wpdb;

        $earliest = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT MIN(expires_at) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d AND status = %s',
                $user_id,
                LotStatus::OPEN
            )
        );

        return null === $earliest ? null : (string) $earliest;
    }

    /**
     * Recomputes every open lot's expiry after batch settings change: earned + $months (MySQL clamps to
     * the month's end, as Engine\Expiry\ExpiryPolicy::batch_expires_at() does). With $keep, never sooner
     * than $floor or the lot's stored date. Without it, only a lot with no stored date (batch just turned
     * on) gets the $floor; a stored date moves to the new one even when that is already past.
     *
     * @param bool $keep Whether the admin kept stored dates on shortening.
     * @throws QueryException When the write fails.
     */
    public function restamp_open_expiry( int $months, string $floor, bool $keep ): void {
        global $wpdb;

        $result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'UPDATE `' . esc_sql( $this->table() ) . '`
                 SET expires_at = IF(
                     %d = 1 OR expires_at IS NULL,
                     GREATEST(DATE_ADD(created_at, INTERVAL %d MONTH), CAST(%s AS DATETIME), COALESCE(expires_at, CAST(%s AS DATETIME))),
                     DATE_ADD(created_at, INTERVAL %d MONTH)
                 )
                 WHERE status = %s',
                (int) $keep,
                $months,
                $floor,
                $floor,
                $months,
                LotStatus::OPEN
            )
        );

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (restamp_open_expiry)' );
        }
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
