<?php
namespace YayLoyalty\Pro\Database\Schema;

use YayLoyalty\Database\Schema\AbstractTableSchema;

defined( 'ABSPATH' ) || exit;

/**
 * How ledger rows moved points out of or back into lots. Append-only: never updated or deleted. points is
 * signed and never 0: positive was taken from the lot (redeem, expire, refund_earn, a negative
 * adjustment, or the earlier negative row a new lot's deficit payoff settles), negative was returned to
 * it (refund_redeem). uniq_lot_ledger is the backstop against allocating one row twice from one lot.
 */
final class LotAllocationsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'lot_allocations';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            lot_id bigint unsigned NOT NULL,
            ledger_id bigint unsigned NOT NULL,
            points bigint NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_lot_ledger (lot_id,ledger_id),
            KEY ledger_id (ledger_id)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'lot_id',
            'ledger_id',
            'points',
            'created_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'uniq_lot_ledger',
            'ledger_id',
        ];
    }
}
