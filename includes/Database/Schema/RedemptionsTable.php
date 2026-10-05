<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_redemptions: designed, not built yet. Not yet in
 * Installer::schemas(): registering it there is the step that creates
 * the table on the next upgrade. UNIQUE (order_id, reward_id) is the
 * double-debit guard for the flexible discount, and (reward_id, user_id)
 * serves the `redemption_count` condition. Scaffold: DDL stub.
 */
final class RedemptionsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'redemptions';
    }

    public function definition(): string {
        // TODO(redemption): CREATE TABLE with the columns below, PK id, UNIQUE uk_order_reward (order_id, reward_id), KEY user_id, KEY order_id, KEY reward_id, KEY idx_status_created (status, created_at), KEY idx_reward_user (reward_id, user_id); InnoDB, charset_collate().
        throw new \RuntimeException( 'Not implemented' );
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
            'uk_order_reward',
            'user_id',
            'order_id',
            'reward_id',
            'idx_status_created',
            'idx_reward_user',
        ];
    }
}
