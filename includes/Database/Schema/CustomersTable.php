<?php
namespace YayLoyalty\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * yayloyalty_customers — one row per member (1.0 columns only).
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
 * Later-phase columns (lifetime_spend, tier_*, referral_code, birthday_*) are
 * added by their own release's migration, not created empty here.
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
            last_activity_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            reminder_sent_at datetime DEFAULT NULL,
            joined_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (user_id),
            KEY expires_at (expires_at),
            KEY balance (balance),
            KEY last_activity_at (last_activity_at)
        ) {$this->charset_collate()} ENGINE=InnoDB";
    }

    public function expected_columns(): array {
        return [
            'user_id',
            'balance',
            'pending_points',
            'lifetime_earned',
            'lifetime_redeemed',
            'last_activity_at',
            'expires_at',
            'reminder_sent_at',
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
        ];
    }
}
