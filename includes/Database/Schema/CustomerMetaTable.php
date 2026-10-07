<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Per-customer key-value event log of non-points customer facts. `meta_key` is also the activity
 * log's `event_type`, and `created_at` its `occurred_at`. There is no unique key, because several rows
 * per key are normal. A write-side check, CustomerMetaRepository::has(), keeps `membership_started` and
 * `account_deleted` to one row each. idx_user_key serves the group filter (`meta_key IN (...)`) and the
 * latest-`account_deleted` lookup. A standalone `meta_key` index would never be selective without `user_id`.
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
