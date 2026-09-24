<?php
namespace YayLoyalty\Tests\Unit\Redemption;

use PHPUnit\Framework\TestCase;

/**
 * ZONE 1 (quote) and ZONE 2 (commit) through one service. Specification
 * until the bodies land; the first test is the invariant the split exists
 * for and must become a real assertion (a $wpdb spy) before F08 ships.
 */
final class RedemptionServiceTest extends TestCase {

    public function test_no_database_write_occurs_during_a_quote(): void {
        $this->markTestIncomplete( 'quote() on any cart, balance and intent: zero INSERT/UPDATE/DELETE on $wpdb, no option, no transient, no order; only SessionIntent::get() is read.' );
    }

    public function test_quote_stores_nothing_in_the_session(): void {
        $this->markTestIncomplete( 'quote() never calls SessionIntent::set(); the WC session holds the same single integer before and after.' );
    }

    public function test_discount_value_is_derived_from_the_current_cart_not_a_stored_figure(): void {
        $this->markTestIncomplete( 'Same intent, cart changes from 80.00 to 6.00: the second quote() reports 600 points / 6.00 bound by cart, with nothing recalculated from a stored value.' );
    }

    public function test_quote_denies_when_no_flexible_reward_is_seeded(): void {
        $this->markTestIncomplete( 'RewardRepository::find_by_slug( cart-discount ) null → Result::denied( no_reward ).' );
    }

    public function test_quote_is_the_clamp_for_the_session_intent(): void {
        $this->markTestIncomplete( 'quote() with intent 1000 and balance 350 is Clamp::apply( reward, 1000, 350, subtotal, currency, decimals ): 300 points bound by balance.' );
    }

    public function test_checkout_error_is_null_when_every_requested_point_applies(): void {
        $this->markTestIncomplete( 'intent 500, quote applies 500 → checkout_error() null; intent 0 → null without quoting.' );
    }

    public function test_checkout_error_names_the_binding_ceiling_before_totals_freeze(): void {
        $this->markTestIncomplete( 'intent 1000, quote applies 300 bound by balance → checkout_error() = balance; a denied quote → its reason.' );
    }

    public function test_commit_requotes_from_the_order_never_from_the_session(): void {
        $this->markTestIncomplete( 'commit() with a session intent that differs from the frozen coupon line debits the coupon line\'s points; SessionIntent::get() is never called.' );
    }

    public function test_commit_clears_the_session_intent_in_every_outcome(): void {
        $this->markTestIncomplete( 'redeemed, skipped and duplicate all end with SessionIntent::clear().' );
    }

    public function test_commit_skips_with_an_order_note_and_no_debit_when_the_locked_balance_cannot_cover_it(): void {
        $this->markTestIncomplete( 'Balance drops between quote and commit: Outcome::skipped( insufficient_balance ), no redemptions row, no ledger row, one order note naming the reason; the order is untouched otherwise.' );
    }

    public function test_commit_twice_for_one_order_is_a_duplicate_not_a_second_debit(): void {
        $this->markTestIncomplete( 'The hook fires twice: second commit() is Outcome::duplicate carrying the first row; aggregates unchanged; guard is UNIQUE (order_id, reward_id) plus the pre-check under the lock.' );
    }

    public function test_redeemed_hook_fires_after_commit_and_only_when_redeemed(): void {
        $this->markTestIncomplete( 'yayloyalty_points_redeemed observed after Transaction::run() returns, never inside it, and not for skipped or duplicate.' );
    }
}
