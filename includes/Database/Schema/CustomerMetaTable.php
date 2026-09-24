<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Generic per-customer key-value event log, non-points customer facts.
 * `meta_key` doubles as the activity log's `event_type`; `created_at`
 * doubles as `occurred_at`. No unique key: multiple rows per key are
 * normal (e.g. repeated `points_adjusted`); `account_created`/
 * `account_deleted` once-only-ness is a write-side check
 * (`CustomerMetaRepository::has()`), not a DB constraint — see
 * docs/data-model/customers-and-ledger.md. idx_user_key serves both the group filter
 * (`meta_key IN (...)`) and the `latest(user_id, 'account_deleted')`
 * lookup; no standalone `meta_key` index — never selective without
 * `user_id`.
 */
final class CustomerMetaTable extends AbstractTableSchema {

    public function slug(): string {
        return 'customer_meta';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            meta_key varchar(50) NOT NULL,
            meta_value longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_user_history (user_id,id),
            KEY idx_user_key (user_id,meta_key)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'user_id',
            'meta_key',
            'meta_value',
            'created_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'idx_user_history',
            'idx_user_key',
        ];
    }
}
