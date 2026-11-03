<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Cached aggregates per member. The ledger is the truth. user_id as the
 * primary key is load-bearing: it turns the first-touch race into an
 * ON DUPLICATE KEY no-op. balance and lifetime_earned are signed on
 * purpose, for refund clawbacks. Columns for unbuilt features, such as
 * tiers and referrals, are declared ahead so each feature lands as a data
 * migration, not a schema change. Indexed columns must stay in sync with
 * CustomerRowSort::KEYS. reminder_for is the expires_at the last expiry reminder
 * announced, so each date is reminded once whatever the reminder timing.
 * birthday_month and birthday_day are a site-calendar date with no year.
 * tier_manual_id is a staff-assigned floor; tier_manual_until NULL with a
 * tier_manual_id set means the floor has no end.
 */
final class CustomersTable extends AbstractTableSchema {

    public function slug(): string {
        return 'customers';
    }

    public function definition(): string {
        $table = $this->table_name();

        return "CREATE TABLE {$table} (
            user_id bigint unsigned NOT NULL,
            balance bigint NOT NULL DEFAULT 0,
            pending_points bigint unsigned NOT NULL DEFAULT 0,
            lifetime_earned bigint NOT NULL DEFAULT 0,
            lifetime_redeemed bigint unsigned NOT NULL DEFAULT 0,
            lifetime_spend decimal(19,4) NOT NULL DEFAULT 0,
            last_activity_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            reminder_sent_at datetime DEFAULT NULL,
            reminder_for datetime DEFAULT NULL,
            tier_id bigint unsigned DEFAULT NULL,
            tier_evaluated_at datetime DEFAULT NULL,
            tier_progress_value decimal(19,4) DEFAULT NULL,
            tier_held_until datetime DEFAULT NULL,
            tier_achieved_at datetime DEFAULT NULL,
            tier_manual_id bigint unsigned DEFAULT NULL,
            tier_manual_until datetime DEFAULT NULL,
            referral_code varchar(32) DEFAULT NULL,
            birthday_month tinyint DEFAULT NULL,
            birthday_day tinyint DEFAULT NULL,
            joined_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id),
            KEY expires_at (expires_at),
            KEY balance (balance),
            KEY last_activity_at (last_activity_at),
            KEY lifetime_earned (lifetime_earned),
            KEY lifetime_redeemed (lifetime_redeemed),
            KEY joined_at (joined_at),
            KEY tier_id (tier_id),
            KEY tier_held_until (tier_held_until),
            KEY tier_manual_until (tier_manual_until),
            UNIQUE KEY referral_code (referral_code)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'user_id',
            'balance',
            'pending_points',
            'lifetime_earned',
            'lifetime_redeemed',
            'lifetime_spend',
            'last_activity_at',
            'expires_at',
            'reminder_sent_at',
            'reminder_for',
            'tier_id',
            'tier_evaluated_at',
            'tier_progress_value',
            'tier_held_until',
            'tier_achieved_at',
            'tier_manual_id',
            'tier_manual_until',
            'referral_code',
            'birthday_month',
            'birthday_day',
            'joined_at',
            'updated_at',
        ];
    }

    public function expected_indexes(): array {
        return [
            'PRIMARY',
            'expires_at',
            'balance',
            'last_activity_at',
            'lifetime_earned',
            'lifetime_redeemed',
            'joined_at',
            'tier_id',
            'tier_held_until',
            'tier_manual_until',
            'referral_code',
        ];
    }
}
