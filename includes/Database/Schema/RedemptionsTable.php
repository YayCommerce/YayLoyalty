<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_redemptions: one row per reward claim. The flexible discount's double-debit guard is the ledger's
 * idempotency key, not an index here, so one order can carry several rows of one reward.
 * uk_coupon guards catalog coupons: coupon_id is NULL for the flexible discount, so flexible discount rows never collide.
 */
final class RedemptionsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'redemptions';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            reward_id bigint unsigned NOT NULL,
            order_id bigint unsigned DEFAULT NULL,
            points bigint unsigned NOT NULL,
            value decimal(19,4) NOT NULL DEFAULT 0,
            currency varchar(3) NOT NULL DEFAULT '',
            base_value decimal(19,4) NOT NULL DEFAULT 0,
            rate_snapshot decimal(19,4) NOT NULL DEFAULT 0,
            coupon_code varchar(100) DEFAULT NULL,
            coupon_id bigint unsigned DEFAULT NULL,
            status varchar(12) NOT NULL,
            restored_points bigint unsigned NOT NULL DEFAULT 0,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_coupon (coupon_id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY reward_id (reward_id),
            KEY idx_status_created (status,created_at),
            KEY idx_reward_user (reward_id,user_id)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'user_id',
            'reward_id',
            'order_id',
            'points',
            'value',
            'currency',
            'base_value',
            'rate_snapshot',
            'coupon_code',
            'coupon_id',
            'status',
            'restored_points',
            'expires_at',
            'created_at',
            'updated_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'uk_coupon',
            'user_id',
            'order_id',
            'reward_id',
            'idx_status_created',
            'idx_reward_user',
        ];
    }
}
