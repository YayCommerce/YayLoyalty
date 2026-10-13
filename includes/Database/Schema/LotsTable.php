<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Per-earning expiry batches, kept off the ledger so the ledger stays append-only. One lot per positive
 * ledger row, in both builds. The schema allows ledger_id NULL (NULLs never collide on uniq_ledger_id),
 * but nothing writes one. remaining = points - SUM(lot_allocations.points), the only mutable counter
 * beside the customer aggregates. expires_at NULL never expires, so FIFO order is
 * `expires_at IS NULL, expires_at, id`. status (open, consumed, expired) flips only with a write, never
 * by a clock. Spec: docs/specs/2026-10-13-points-expiry-design.md.
 */
final class LotsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'lots';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            ledger_id bigint unsigned DEFAULT NULL,
            points bigint unsigned NOT NULL,
            remaining bigint unsigned NOT NULL,
            expires_at datetime DEFAULT NULL,
            status varchar(10) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_ledger_id (ledger_id),
            KEY idx_user_open (user_id,status,expires_at),
            KEY idx_status_expires (status,expires_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'user_id',
            'ledger_id',
            'points',
            'remaining',
            'expires_at',
            'status',
            'created_at',
            'updated_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'uniq_ledger_id',
            // The FIFO pick under the customer lock.
            'idx_user_open',
            // The daily expiry job.
            'idx_status_expires',
        ];
    }
}
