<?php
namespace YayLoyalty\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/** The award writer's invariants. All need $wpdb — specification only until a wp-phpunit harness exists. */
final class LedgerServiceTest extends TestCase {

    public function test_earn_locks_the_customer_row_before_inserting(): void {
        $this->markTestIncomplete( 'ensure_row then SELECT … FOR UPDATE precede the INSERT; two concurrent earns for one user serialise.' );
    }

    public function test_balance_after_stays_an_unbroken_chain_per_customer(): void {
        $this->markTestIncomplete( 'For every consecutive pair of rows per user: prev.balance_after + points = this.balance_after.' );
    }

    public function test_unique_index_is_the_guard_and_the_precheck_is_only_an_optimisation(): void {
        $this->markTestIncomplete( 'With find_by_idempotency_key stubbed to null, a second earn() still returns Outcome::duplicate via error 1062.' );
    }

    public function test_duplicate_leaves_aggregates_and_pending_untouched(): void {
        $this->markTestIncomplete( 'balance, lifetime_earned, last_activity_at unchanged after a duplicate earn().' );
    }

    public function test_manual_adjustments_stay_non_idempotent(): void {
        $this->markTestIncomplete( 'adjust() twice with the same note writes two rows: idempotency_key is NULL.' );
    }

    public function test_ledger_recorded_fires_after_the_outermost_commit(): void {
        $this->markTestIncomplete( 'Nested in an outer Transaction::run(), yayloyalty_ledger_recorded fires after the outer commit, not the savepoint release.' );
    }
}
