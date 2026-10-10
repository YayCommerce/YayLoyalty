<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only truth, from which customers is derived. uniq_idempotency_key on the single
 * `idempotency_key` column is the idempotency guarantee: one string, not a composite with nullable
 * parts. Engine\Earning\IdempotencyKey composes it from type, user, reference and trigger, so an order
 * may earn once per trigger per user. NULL keys, on manual adjustments, never collide, so those rows
 * are deliberately not idempotent. idx_user_history_type is (user_id, id, type): id orders history
 * because created_at can tie within a second, and type lets the drawer's per-type count and type filter
 * read the index instead of the rows.
 */
final class LedgerTable extends AbstractTableSchema {

    public function slug(): string {
        return 'ledger';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            points bigint NOT NULL,
            balance_after bigint NOT NULL,
            type varchar(20) NOT NULL,
            source varchar(20) NOT NULL,
            reference_type varchar(20) DEFAULT NULL,
            reference_id bigint unsigned DEFAULT NULL,
            campaign_id bigint unsigned DEFAULT NULL,
            redemption_id bigint unsigned DEFAULT NULL,
            order_id bigint unsigned DEFAULT NULL,
            note varchar(500) DEFAULT NULL,
            actor_id bigint unsigned DEFAULT NULL,
            idempotency_key varchar(96) DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_idempotency_key (idempotency_key),
            KEY idx_user_history_type (user_id,id,type),
            KEY idx_user_created (user_id,created_at),
            KEY campaign_id (campaign_id),
            KEY redemption_id (redemption_id),
            KEY order_id (order_id),
            KEY type_created (type,created_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'user_id',
            'points',
            'balance_after',
            'type',
            'source',
            'reference_type',
            'reference_id',
            'campaign_id',
            'redemption_id',
            'order_id',
            'note',
            'actor_id',
            'idempotency_key',
            'created_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            // Missing means duplicate awards. Installer::verify() exists for this.
            'uniq_idempotency_key',
            'idx_user_history_type',
            // LedgerRepository::points_earned_today() reads one day of a customer's rows under the award lock.
            'idx_user_created',
            'campaign_id',
            'redemption_id',
            'order_id',
            'type_created',
        ];
    }
}
