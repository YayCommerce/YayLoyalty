<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_lots, written only by Services\LotWriter under the customer lock, which serialises every
 * write for one customer, so reads here take no row lock. Rows stay arrays: nothing needs a lot model.
 */
class LotRepository {

    public const STATUS_OPEN     = 'open';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_EXPIRED  = 'expired';

    public function table(): string {
        return Table::name( 'lots' );
    }

    /**
     * The customer's open lots in the order they are spent: earliest expiry first, never-expiring last.
     *
     * @return array<int, array{id: int, remaining: int}>
     */
    public function open_lots_fifo( int $user_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT id, remaining FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND status = %s
                 ORDER BY expires_at IS NULL, expires_at, id',
                $user_id,
                self::STATUS_OPEN
            ),
            ARRAY_A
        );

        return array_map(
            static fn( array $row ): array => [
                'id'        => (int) $row['id'],
                'remaining' => (int) $row['remaining'],
            ],
            is_array( $rows ) ? $rows : []
        );
    }

    /** What the customer's open lots due by $at still hold: what the batch model expires at $at. */
    public function sum_due( int $user_id, string $at ): int {
        global $wpdb;

        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT COALESCE( SUM( remaining ), 0 ) FROM `' . esc_sql( $this->table() ) . '`
                 WHERE user_id = %d AND status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
                $user_id,
                self::STATUS_OPEN,
                $at
            )
        );
    }

    /** The earliest date any of the customer's open lots expires, or null when none does. */
    public function earliest_open_expiry( int $user_id ): ?string {
        global $wpdb;

        $earliest = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $wpdb->prepare(
                'SELECT MIN( expires_at ) FROM `' . esc_sql( $this->table() ) . '` WHERE user_id = %d AND status = %s',
                $user_id,
                self::STATUS_OPEN
            )
        );

        return null === $earliest ? null : (string) $earliest;
    }

    /**
     * Re-dates every open lot, as ExpiryRules::lot_expires_at() would one by one: $months after it was
     * earned, clamped to month end by DATE_ADD itself, or never when $months is null.
     *
     * @throws QueryException When the write fails.
     */
    public function recompute_expiry( ?int $months ): void {
        global $wpdb;

        $table = esc_sql( $this->table() );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a set-wide update on the plugin's own escaped table; every value is bound.
        $result = null === $months
            ? $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET expires_at = NULL WHERE status = %s AND expires_at IS NOT NULL", self::STATUS_OPEN ) )
            : $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET expires_at = DATE_ADD( created_at, INTERVAL %d MONTH ) WHERE status = %s", $months, self::STATUS_OPEN ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() . ' (recompute_expiry)' );
        }
    }

    /**
     * @return int The new lot's id.
     * @throws QueryException When the write fails.
     */
    public function insert_lot( int $user_id, int $ledger_id, int $points, int $remaining, ?string $expires_at, string $created_at ): int {
        global $wpdb;

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $this->table(),
            [
                'user_id'    => $user_id,
                'ledger_id'  => $ledger_id,
                'points'     => $points,
                'remaining'  => $remaining,
                'expires_at' => $expires_at,
                'status'     => 0 === $remaining ? self::STATUS_CONSUMED : self::STATUS_OPEN,
                'created_at' => $created_at,
                'updated_at' => Dates::now(),
            ],
            [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $result ) {
            throw new QueryException( 'insert', $wpdb->last_error, $this->table() );
        }

        return (int) $wpdb->insert_id;
    }

    /** @throws QueryException When the write fails. */
    public function set_remaining( int $lot_id, int $remaining, string $status ): void {
        global $wpdb;

        $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $this->table(),
            [
                'remaining'  => $remaining,
                'status'     => $status,
                'updated_at' => Dates::now(),
            ],
            [ 'id' => $lot_id ],
            [ '%d', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $result ) {
            throw new QueryException( 'update', $wpdb->last_error, $this->table() );
        }
    }
}
