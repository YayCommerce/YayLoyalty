<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * One row per slice a negative ledger row (redeem, expire, adjust, correction, refund_earn) took from
 * a lot, so every lot's consumption traces back to the rows that spent it. Append-only, written with
 * the lot's decrement by Services\LotService under LedgerService.
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
            points bigint unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lot_id (lot_id),
            KEY ledger_id (ledger_id)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [ 'id', 'lot_id', 'ledger_id', 'points', 'created_at' ];
    }

    public function expected_indexes(): array {
        return [ 'PRIMARY', 'lot_id', 'ledger_id' ];
    }
}
