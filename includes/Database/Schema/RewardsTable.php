<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_rewards — ways to spend points (1.0 columns only).
 *
 * `status` stores merchant intent only: active, paused, draft, archived.
 * Liveness (`scheduled` / `ended`) is NEVER stored — it is derived at read time
 * from status plus starts_at/ends_at, the same rule campaigns use. See
 * Enums\Status::is_live_at() and Enums\Status::resolve_display(). No cron may
 * flip a status.
 *
 * `cost_points` is NULL only for `flexible_discount` — the customer picks the
 * amount on a slider. Every other type is a fixed-cost catalog item and must
 * have `cost_points` >= 1. The database cannot express a rule that depends on
 * another column's value, so this is enforced in PHP: see
 * Reward::cost_points_is_valid(). A fixed_coupon that slipped through with a
 * null cost would be a free redemption.
 */
final class RewardsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'rewards';
    }

    public function definition(): string {
        $table = $this->table_name();

        // dbDelta grammar: lowercase types, one field per line, named KEYs (never
        // INDEX, never bare UNIQUE), two spaces after PRIMARY KEY, ENGINE stated.
        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(64) NOT NULL,
            type varchar(32) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            sort_order int NOT NULL DEFAULT 0,
            cost_points bigint unsigned DEFAULT NULL,
            config longtext NOT NULL,
            conditions longtext NOT NULL,
            starts_at datetime DEFAULT NULL,
            ends_at datetime DEFAULT NULL,
            is_builtin tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint unsigned DEFAULT NULL,
            updated_by bigint unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            archived_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_slug (slug),
            KEY idx_catalog (status,sort_order),
            KEY idx_archived (status,archived_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'name',
            'slug',
            'type',
            'status',
            'sort_order',
            'cost_points',
            'config',
            'conditions',
            'starts_at',
            'ends_at',
            'is_builtin',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
            'archived_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'uk_slug',
            'idx_catalog',
            'idx_archived',
        ];
    }
}
