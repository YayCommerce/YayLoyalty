<?php
namespace YayLoyalty\Pro\Database;

use YayLoyalty\Database\Schema\AbstractTableSchema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_referrals: one row per friend an advocate brought in. The unique friend means a friend is referred once.
 */
final class ReferralsTable extends AbstractTableSchema {

    public function slug(): string {
        return 'referrals';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            advocate_user_id bigint unsigned NOT NULL,
            friend_user_id bigint unsigned NOT NULL,
            referral_code varchar(32) NOT NULL DEFAULT '',
            status varchar(12) NOT NULL,
            reject_reason varchar(32) DEFAULT NULL,
            terms longtext NOT NULL,
            coupon_expires_at datetime DEFAULT NULL,
            first_order_id bigint unsigned DEFAULT NULL,
            hold_until datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY friend_user_id (friend_user_id),
            KEY idx_advocate_status (advocate_user_id,status),
            KEY first_order_id (first_order_id),
            KEY idx_status_hold (status,hold_until),
            KEY idx_status_coupon_expiry (status,coupon_expires_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'id',
            'advocate_user_id',
            'friend_user_id',
            'referral_code',
            'status',
            'reject_reason',
            'terms',
            'coupon_expires_at',
            'first_order_id',
            'hold_until',
            'created_at',
            'updated_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'friend_user_id',
            'idx_advocate_status',
            'first_order_id',
            'idx_status_hold',
            'idx_status_coupon_expiry',
        ];
    }
}
