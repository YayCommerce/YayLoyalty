<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Which earnings make up each balance, FIFO: one lot per positive ledger row (uniq_ledger_id), sized
 * to the part of the row above zero. `remaining` is the only mutable counter. Invariant: after every
 * committed LedgerService write, a user's SUM(remaining) over `open` lots equals max(0, balance).
 * Only Services\LotBook writes it, and only LedgerService calls LotBook, inside its transaction.
 * `expires_at` is set only in batch expiry. `created_at` is the ledger row's, the earned date.
 */
final class LotsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'lots';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            ledger_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            points bigint unsigned NOT NULL,
            remaining bigint unsigned NOT NULL,
            expires_at datetime DEFAULT NULL,
            status varchar(10) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_ledger_id (ledger_id),
            KEY idx_user_status (user_id,status,id),
            KEY idx_status_expires (status,expires_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [ 'id', 'ledger_id', 'user_id', 'points', 'remaining', 'expires_at', 'status', 'created_at' ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            // Missing means a ledger row could open two lots.
            'uniq_ledger_id',
            'idx_user_status',
            'idx_status_expires',
        ];
    }
}
