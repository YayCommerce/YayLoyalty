<?php
namespace YayLoyalty\Tests\Unit\Redemption;

use PHPUnit\Framework\TestCase;

/** ZONE 2's one transaction. All need $wpdb — specification only until a wp-phpunit harness exists. */
final class CommitWriterTest extends TestCase {

    public function test_commit_is_one_transaction_with_a_locking_read_first(): void {
        $this->markTestIncomplete( 'ensure_row then SELECT … FOR UPDATE on the customer precede every insert; the redemptions row and the ledger row commit or roll back together.' );
    }

    public function test_commit_revalidates_with_the_locked_balance_not_the_quoted_one(): void {
        $this->markTestIncomplete( 'Clamp runs again inside the lock with lock_and_get()->get_balance(); a balance that dropped since the cart quote yields fewer points and a skip.' );
    }

    public function test_redemption_row_and_ledger_row_reference_each_other(): void {
        $this->markTestIncomplete( 'ledger.redemption_id and ledger.reference_id both equal the redemptions row id; reference_type = redemption; ledger.order_id = the order.' );
    }

    public function test_ledger_row_goes_through_ledger_service_redeem_not_a_second_writer(): void {
        $this->markTestIncomplete( 'CommitWriter never calls LedgerRepository::insert() directly; LedgerService::redeem() is the only ledger write and it moves lifetime_redeemed.' );
    }

    public function test_value_and_points_are_frozen_onto_the_order_meta(): void {
        $this->markTestIncomplete( '_yayloyalty_points_redeemed and _yayloyalty_redeem_value are written HPOS-safely after the rows, and never read back to compute anything.' );
    }

    public function test_a_race_failure_leaves_the_order_created_without_the_debit_and_noted(): void {
        $this->markTestIncomplete( 'A duplicate-key on the redemptions insert from a concurrent commit: Outcome::duplicate, no retry, no rollback of the order, one order note.' );
    }

    public function test_nothing_slow_runs_inside_the_transaction(): void {
        $this->markTestIncomplete( 'No hook fires and no HTTP or mail runs between START TRANSACTION and COMMIT; yayloyalty_ledger_recorded and yayloyalty_points_redeemed fire after.' );
    }
}
