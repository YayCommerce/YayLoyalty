<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Sql;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_lots and its allocations. A take decrements a lot and writes the allocation that explains
 * it, so the two tables share one repository. Rows are plain arrays: nothing outside LotService reads them.
 * Callers hold the customer's row lock (LedgerService), which serialises each user's lots, so reads
 * here do not lock: a locking read over a user with no lots gap-locks, and two customers' first lots
 * would deadlock. take()'s guarded UPDATE still refuses a lot that changed under a stale read.
 * The exceptions are the expiry job's (Engine\Expiry): restamp_open_expiry() and the reminder queries
 * run without the lock, write only expires_at and reminded_at through guarded UPDATEs, and return sums
 * or ids, never rows.
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
            throw new QueryException( 'insert', esc_html( $wpdb->last_error ), esc_html( $this->table() ) );
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
        $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
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
            throw new QueryException( 'update', esc_html( $wpdb->last_error ), esc_html( $this->table() . ' (take)' ) );
        }

        if ( 1 !== (int) $result ) {
            throw new \RuntimeException( esc_html( sprintf( 'YayLoyalty: lot %d cannot give %d points.', $lot_id, $points ) ) );
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
            throw new QueryException( 'insert', esc_html( $wpdb->last_error ), esc_html( $this->allocations_table() ) );
        }
    }

    /** Points that expired out of $ledger_id's lot: the slices EXPIRE rows took from it. */
    public function expired_from( int $ledger_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COALESCE(SUM(a.points), 0)
                 FROM `' . esc_sql( $this->allocations_table() ) . '` a
                 JOIN `' . esc_sql( $this->table() ) . '` l ON l.id = a.lot_id
                 JOIN `' . esc_sql( Table::name( 'ledger' ) ) . '` g ON g.id = a.ledger_id
                 WHERE l.ledger_id = %d AND g.type = %s',
                $ledger_id,
                LedgerType::EXPIRE
            )
        );
    }

    /**
     * The dates of the first lot $ledger_id took a slice from, or null when it took none.
     *
     * @return array{created_at: string, expires_at: ?string}|null
     */
    public function first_lot_taken_by( int $ledger_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT l.created_at, l.expires_at
                 FROM `' . esc_sql( $this->allocations_table() ) . '` a
                 JOIN `' . esc_sql( $this->table() ) . '` l ON l.id = a.lot_id
                 WHERE a.ledger_id = %d ORDER BY a.id LIMIT 1',
                $ledger_id
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        return [
            'created_at' => (string) $row['created_at'],
            'expires_at' => null === $row['expires_at'] ? null : (string) $row['expires_at'],
        ];
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

    /** The points left in a user's open lots that expire by $expires_at (UTC); read-only, unlike claim_reminder_lots(). */
    public function remaining_expiring_by( int $user_id, string $expires_at ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COALESCE(SUM(remaining), 0) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d AND status = %s AND expires_at <= %s',
                $user_id,
                LotStatus::OPEN,
                $expires_at
            )
        );
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

        $new_date        = 'IF(
                %d = 1 OR expires_at IS NULL,
                GREATEST(DATE_ADD(created_at, INTERVAL %d MONTH), CAST(%s AS DATETIME), COALESCE(expires_at, CAST(%s AS DATETIME))),
                DATE_ADD(created_at, INTERVAL %d MONTH)
            )';
        $new_date_values = [ (int) $keep, $months, $floor, $floor, $months ];

        // reminded_at first: MySQL assigns left to right, so it still compares the old expires_at.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $new_date holds only placeholders, bound twice by $new_date_values: the sniff cannot count through it.
        $result = $wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'UPDATE `' . esc_sql( $this->table() ) . "`
                 SET reminded_at = IF( {$new_date} <=> expires_at, reminded_at, NULL ),
                     expires_at = {$new_date}
                 WHERE status = %s",
                array_merge( $new_date_values, $new_date_values, [ LotStatus::OPEN ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        if ( false === $result ) {
            throw new QueryException( 'update', esc_html( $wpdb->last_error ), esc_html( $this->table() . ' (restamp_open_expiry)' ) );
        }
    }

    /**
     * Customers with an open lot due an expiry reminder (reminder_due_sql()), for the batch reminder step.
     *
     * @return int[]
     */
    public function reminder_due_user_ids( string $now, string $horizon, int $limit ): array {
        global $wpdb;

        list( $due_sql, $due_values ) = self::reminder_due_sql( $now, $horizon );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $due_sql holds only placeholders, and $due_values binds them: the sniff cannot count through it.
        $ids = $wpdb->get_col( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT DISTINCT user_id FROM `' . esc_sql( $this->table() ) . "` WHERE {$due_sql} LIMIT %d",
                array_merge( $due_values, [ $limit ] )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }

    /**
     * Claims every due lot of $user_id: reads them, then stamps reminded_at = $now on exactly those ids while
     * still unannounced, and sums what it read. A racing run that took any of them first leaves this claim
     * short, and it returns null, so a lot is never announced twice; the lots it did stamp are dropped.
     *
     * @return array{points: int, expires_at: string, is_single_date: bool}|null
     * @throws QueryException When the write fails.
     */
    public function claim_reminder_lots( int $user_id, string $now, string $horizon ): ?array {
        global $wpdb;

        list( $due_sql, $due_values ) = self::reminder_due_sql( $now, $horizon );
        $table                        = esc_sql( $this->table() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $due_sql and $in hold only placeholders, bound by the arrays beside them: the sniff cannot count through them.
        $lots = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                "SELECT id, remaining, expires_at FROM `{$table}` WHERE user_id = %d AND {$due_sql}",
                array_merge( [ $user_id ], $due_values )
            ),
            ARRAY_A
        );

        if ( empty( $lots ) ) {
            return null;
        }

        $ids    = array_map( 'intval', array_column( $lots, 'id' ) );
        $in     = Sql::in_placeholders( $ids, '%d' );
        $result = $wpdb->query( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                "UPDATE `{$table}` SET reminded_at = %s WHERE reminded_at IS NULL AND id IN ({$in})",
                array_merge( [ $now ], $ids )
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        if ( false === $result ) {
            throw new QueryException( 'update', esc_html( $wpdb->last_error ), esc_html( $this->table() . ' (claim_reminder_lots)' ) );
        }

        if ( count( $ids ) !== $result ) {
            return null;
        }

        $dates = array_column( $lots, 'expires_at' );
        // Days as the customer reads them: the email shows each date in the site's time zone.
        $days = array_unique(
            array_map(
                static function ( string $at ): string {
                    return Dates::to_site_tz( $at, 'Y-m-d' );
                },
                $dates
            )
        );

        return [
            'points'         => (int) array_sum( array_column( $lots, 'remaining' ) ),
            'expires_at'     => (string) min( $dates ),
            'is_single_date' => 1 === count( $days ),
        ];
    }

    /**
     * The one batch reminder rule, for the selector and the claim alike: an open lot expiring after $now
     * and by $horizon that no reminder has announced yet.
     *
     * @return array{0: string, 1: array<int, string>} SQL with placeholders, and their values.
     */
    private static function reminder_due_sql( string $now, string $horizon ): array {
        return [
            'status = %s AND reminded_at IS NULL AND expires_at > %s AND expires_at <= %s',
            [ LotStatus::OPEN, $now, $horizon ],
        ];
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
