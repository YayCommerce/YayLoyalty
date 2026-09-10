<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_customers — one row per member.
 *
 * A 1:1 extension of wp_users holding cached aggregates, so the storefront can
 * read a balance in O(1). The ledger is the truth; this table is a cache that
 * can be rebuilt by replaying it.
 *
 * user_id IS THE PRIMARY KEY, and that is load-bearing. Rows are created lazily
 * on first activity, and SELECT ... FOR UPDATE on a row that does not exist
 * takes a gap lock, not a row lock — so two concurrent first-touch writes would
 * both find nothing and both insert. The primary key turns that race into a
 * no-op via INSERT ... ON DUPLICATE KEY, which is why CustomerRepository
 * materialises the row before locking it. Without the key, the split rows would
 * make customers.balance = SUM(ledger.points) permanently false, and the ledger
 * is append-only so it could not be repaired.
 *
 * LATER-PHASE COLUMNS ARE DECLARED HERE AHEAD OF THEIR RELEASE, ON PURPOSE.
 * `lifetime_spend`, `tier_id`, `tier_evaluated_at`, `tier_progress_value`
 * (1.2), `referral_code` (1.1) and `birthday_month`/`birthday_day` (Pro) sit
 * unused — nothing reads or writes them yet — until their owning feature
 * ships. Declaring the column now means that feature lands as a data
 * migration for existing installs (populate a NULL/0 column), not a schema
 * change; NULL/0 is a safe default for a column nothing touches yet. When
 * implementing one of these, grep for its name first: the column and its
 * index already exist, only the service/model/REST code is missing.
 *
 * `idx_tier_review (tier_id, tier_evaluated_at)` is NOT added yet — the
 * downgrade job that would query it does not exist. See docs/data-model.md.
 *
 * `balance` and `lifetime_earned` ARE DELIBERATELY SIGNED (`bigint`, not
 * `bigint unsigned`). A clawback after a refund can legitimately push either
 * below zero; the dashboard flags a negative, nothing here guards against it.
 * Do not "fix" these to UNSIGNED — see Models\Customer for the same note.
 *
 * `lifetime_earned`, `lifetime_redeemed` and `joined_at` are indexed for the
 * same reason `balance` and `last_activity_at` are: CustomerRepository::
 * sortable_columns() promises the admin list can sort by any of them, and an
 * ORDER BY on an unindexed column here means a filesort over every member row
 * once the table is no longer tiny. Keep expected_indexes() in this file in
 * sync with sortable_columns() — a column added to one without the other is
 * the bug this pairing exists to prevent.
 */
final class CustomersTable extends AbstractTableSchema {

    public function slug(): string {
        return 'customers';
    }

    public function definition(): string {
        $table = $this->table_name();

        // dbDelta grammar: named KEYs, two spaces after PRIMARY KEY, one space
        // between column name and type. See AbstractTableSchema.
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
            tier_id bigint unsigned DEFAULT NULL,
            tier_evaluated_at datetime DEFAULT NULL,
            tier_progress_value decimal(19,4) DEFAULT NULL,
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
            'tier_id',
            'tier_evaluated_at',
            'tier_progress_value',
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
            'referral_code',
        ];
    }
}
