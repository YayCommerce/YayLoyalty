<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_campaigns — earning rules (1.0 columns only).
 *
 * The event column is `trigger_type`, NOT `trigger`. `trigger` is a reserved
 * word in MySQL/MariaDB: naming the column that forces a backtick into every
 * statement that touches it, and a single missed backtick is a syntax error on
 * the one code path nobody tests. `trigger_type` sidesteps the whole class of
 * bug — nothing here or in CampaignRepository backticks anything.
 *
 * `status` stores merchant intent only: active, paused, draft, archived.
 * Liveness ("scheduled" / "ended") is NEVER stored — it is derived at read time
 * from status plus starts_at/ends_at, evaluated against the EVENT's timestamp.
 * See Enums\Status::is_live_at() and Enums\Status::resolve_display(). No cron
 * may flip a status.
 *
 */
final class CampaignsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'campaigns';
    }

    public function definition(): string {
        $table = $this->table_name();

        // dbDelta grammar: lowercase types, one field per line, named KEYs (never
        // INDEX, never bare UNIQUE), two spaces after PRIMARY KEY, ENGINE stated.
        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(64) NOT NULL,
            trigger_type varchar(32) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            priority int NOT NULL DEFAULT 0,
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
            KEY idx_lookup (trigger_type,status,priority),
            KEY idx_archived (status,archived_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'name',
            'slug',
            'trigger_type',
            'status',
            'priority',
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
            'idx_lookup',
            'idx_archived',
        ];
    }
}
