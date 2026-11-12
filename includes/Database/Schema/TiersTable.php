<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_tiers. Rows are soft-deleted (deleted_at) so activity history and stored
 * reward conditions keep resolving a tier's name. Name and threshold are unique among
 * live tiers only, which a unique index can't express, because a deleted row would
 * collide with it; the tiers controller enforces both. tier_rank follows threshold order.
 */
final class TiersTable extends AbstractTableSchema {

    public function slug(): string {
        return 'tiers';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            threshold bigint unsigned NOT NULL,
            tier_rank int unsigned NOT NULL,
            multiplier decimal(4,2) NOT NULL DEFAULT 1.00,
            entry_bonus bigint unsigned NOT NULL DEFAULT 0,
            color varchar(7) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            deleted_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_live_rank (deleted_at,tier_rank)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'name',
            'threshold',
            'tier_rank',
            'multiplier',
            'entry_bonus',
            'color',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'idx_live_rank',
        ];
    }
}
