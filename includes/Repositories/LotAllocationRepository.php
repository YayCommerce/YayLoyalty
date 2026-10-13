<?php
namespace YayLoyalty\Repositories;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/** yayloyalty_lot_allocations: append-only, written only by Services\LotWriter under the customer lock. */
class LotAllocationRepository {

    public function table(): string {
        return Table::name( 'lot_allocations' );
    }

    /**
     * Records that ledger row $ledger_id took $points from lot $lot_id (negative: returned them).
     *
     * @throws QueryException When the write fails, a repeat of the same (lot, row) pair included.
     */
    public function insert( int $lot_id, int $ledger_id, int $points, string $created_at ): void {
        global $wpdb;

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a direct query: the plugin's own table has no WordPress API.
            $this->table(),
            [
                'lot_id'     => $lot_id,
                'ledger_id'  => $ledger_id,
                'points'     => $points,
                'created_at' => $created_at,
            ],
            [ '%d', '%d', '%d', '%s' ]
        );

        if ( false === $result ) {
            throw new QueryException( 'insert', $wpdb->last_error, $this->table() );
        }
    }
}
