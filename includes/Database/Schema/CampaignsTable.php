<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_campaigns — earning rules (1.0 columns only).
 *
 * `trigger` IS A RESERVED WORD in MySQL 8. It must be backticked in the DDL and
 * in every query that names it, or the statement is a syntax error. That is the
 * single most likely way this table fails to install.
 *
 * `status` stores merchant intent only. Liveness is derived at read time from
 * status plus starts_at/ends_at, evaluated against the EVENT's timestamp — see
 * Enums\Status::is_live_at(). The starts_at/ends_at indexes exist to make that
 * selection cheap, not to support any job: no cron may flip a status.
 */
final class CampaignsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'campaigns';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(64) NOT NULL,
            `trigger` varchar(20) NOT NULL,
            status varchar(10) NOT NULL DEFAULT 'active',
            priority int NOT NULL DEFAULT 0,
            config longtext NOT NULL,
            conditions longtext NOT NULL,
            starts_at datetime DEFAULT NULL,
            ends_at datetime DEFAULT NULL,
            is_builtin tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY trigger_status_priority (`trigger`,status,priority),
            KEY starts_at (starts_at),
            KEY ends_at (ends_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'name',
            'slug',
            'trigger',
            'status',
            'priority',
            'config',
            'conditions',
            'starts_at',
            'ends_at',
            'is_builtin',
            'created_by',
            'created_at',
            'updated_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'slug',
            'trigger_status_priority',
            'starts_at',
            'ends_at',
        ];
    }
}
