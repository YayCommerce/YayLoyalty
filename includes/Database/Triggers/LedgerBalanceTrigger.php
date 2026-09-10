<?php
namespace YayLoyalty\Database\Triggers;

use YayLoyalty\Utils\Table;

defined( 'ABSPATH' ) || exit;

/**
 * AFTER INSERT trigger on `ledger` that keeps `customers.balance` in sync.
 *
 * Fires inside the same transaction as LedgerService::record()'s insert, on a
 * customer row that transaction already holds a FOR UPDATE lock on (see
 * CustomerRepository::lock_and_get()) — no new lock, no new deadlock surface.
 *
 * `balance_after` — the audit-chain value stored on the ledger row itself —
 * is still computed in PHP, from the balance read BEFORE this insert. This
 * trigger only propagates the delta afterwards; it cannot touch
 * balance_after, because NEW's columns are already fixed by the time AFTER
 * INSERT fires.
 *
 * `lifetime_earned` / `lifetime_redeemed` / `last_activity_at` deliberately
 * stay in CustomerRepository::apply_delta(), not here. Unlike balance they
 * are not a plain running sum of `points`: LedgerType decides per-row whether
 * a movement counts toward them (see LedgerService::apply_aggregates()), and
 * mirroring that decision in SQL would mean keeping the same business rule in
 * two places in sync by hand.
 *
 * REQUIRES THE TRIGGER PRIVILEGE on the DB user. dbDelta has no concept of
 * triggers, so Installer creates and verifies this one explicitly — see
 * Installer::sync_triggers() and Installer::verify(). A host that denies the
 * privilege leaves `balance` permanently stale with no PHP-side fallback;
 * verify()'s log entry is the only signal.
 */
final class LedgerBalanceTrigger {

    /**
     * Table-qualified: MySQL trigger names live in one namespace per database,
     * and multisite puts every blog's `ledger` table in that same database
     * under a different prefix. Without the table name in it, activating on
     * blog 2 would collide with the trigger already created for blog 1.
     */
    public function name(): string {
        return Table::name( 'ledger' ) . '_balance_ai';
    }

    public function create_sql(): string {
        $ledger    = Table::name( 'ledger' );
        $customers = Table::name( 'customers' );

        return 'CREATE TRIGGER `' . $this->name() . '`
            AFTER INSERT ON `' . $ledger . '`
            FOR EACH ROW
            UPDATE `' . $customers . '` SET balance = balance + NEW.points WHERE user_id = NEW.user_id';
    }

    public function drop_sql(): string {
        return 'DROP TRIGGER IF EXISTS `' . $this->name() . '`';
    }

    /**
     * Whether this trigger exists, read straight from information_schema.
     *
     * SHOW TRIGGERS has no WHERE clause on name; filtering its full output in
     * PHP for one row is wasted work next to one indexed lookup.
     */
    public function exists(): bool {
        global $wpdb;

        $found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = %s AND TRIGGER_NAME = %s',
                $wpdb->dbname,
                $this->name()
            )
        );

        return null !== $found;
    }
}
