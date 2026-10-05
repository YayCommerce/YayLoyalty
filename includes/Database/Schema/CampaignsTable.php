<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_campaigns. The event column is trigger_type, not trigger: a
 * reserved word would need a backtick in every statement, and one missed
 * is a syntax error.
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
