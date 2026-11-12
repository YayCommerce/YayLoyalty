<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\Reversal;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** Redemption\Reversal: what a cancel, a refund and their undo return and take again, called directly with real orders and refund objects. */
final class ReversalTest extends IntegrationTestCase {

    private function reversal(): Reversal {
        return new Reversal( $this->ledger_service(), new RedemptionRepository(), new CustomerRepository(), new RewardRepository() );
    }

    /** User 7 has 1000 points and spends 500 on an order of 20.00 that totals 15.00. */
    private function redeemed_order( Reward $reward ): \WC_Order {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );

        $order = $this->order_with_points_discount( 7, 20.0, 5.0, $reward->get_coupon_code() );
        $order->set_total( 15.0 );
        $order->save();

        $this->assertSame( Outcome::REDEEMED, $this->commit_writer()->commit( $order, $reward )->get_status() );

        return $order;
    }

    /** A stored refund object, created without WooCommerce's refund hooks. */
    private function refund_of( \WC_Order $order, float $amount ): int {
        $refund = new \WC_Order_Refund();
        $refund->set_parent_id( $order->get_id() );
        $refund->set_currency( $order->get_currency() );
        $refund->set_amount( $amount );
        $refund->set_total( -$amount );

        return (int) $refund->save();
    }

    /** @return array<string, mixed> */
    private function row(): array {
        return $this->redemption_rows( 7 )[0];
    }

    /** @return array<string, mixed> */
    private function last_ledger_row(): array {
        $rows = $this->ledger_rows( 7 );

        return end( $rows );
    }

    public function test_a_cancel_restores_in_full_and_marks_the_row_cancelled(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );

        $outcomes = $this->reversal()->cancelled( $order );

        $this->assertSame( Outcome::AWARDED, $outcomes[0]->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );
        $this->assertSame( 500, (int) $this->row()['restored_points'] );
        $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( 0, (int) $this->customer_row( 7 )['lifetime_redeemed'] );

        $ledger = $this->last_ledger_row();
        $this->assertSame( LedgerType::REFUND_REDEEM, $ledger['type'] );
        $this->assertSame( (int) $this->row()['id'], (int) $ledger['redemption_id'] );
        $this->assertSame( sprintf( 'refund_redeem:7:order:%d:flexible_discount', $order->get_id() ), $ledger['idempotency_key'] );
    }

    public function test_three_one_third_refunds_restore_every_point(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );

        foreach ( [ 5.0, 5.0, 5.0 ] as $amount ) {
            $this->reversal()->refunded( $order, $this->refund_of( $order, $amount ) );
        }

        $this->assertSame( [ 1000, -500, 166, 167, 167 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REFUNDED, $this->row()['status'] );
        $this->assertSame( 500, (int) $this->row()['restored_points'] );
    }

    public function test_a_half_refund_restores_half(): void {
        $order     = $this->redeemed_order( $this->seeded_reward() );
        $refund_id = $this->refund_of( $order, 7.5 );

        $this->reversal()->refunded( $order, $refund_id );

        $this->assertSame( [ 1000, -500, 250 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
        $this->assertSame( 250, (int) $this->row()['restored_points'] );
        $this->assertSame( sprintf( 'refund_redeem:7:refund:%d:flexible_discount', $refund_id ), $this->last_ledger_row()['idempotency_key'] );
    }

    public function test_policy_none_restores_nothing_on_a_refund(): void {
        $order = $this->redeemed_order( $this->seeded_reward( [ 'refund_policy' => 'none' ] ) );

        $outcomes = $this->reversal()->refunded( $order, $this->refund_of( $order, 15.0 ) );

        $this->assertSame( Outcome::SKIPPED, $outcomes[0]->get_status() );
        $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcomes[0]->get_reason() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
    }

    public function test_policy_full_restores_everything_on_the_first_refund(): void {
        $order = $this->redeemed_order( $this->seeded_reward( [ 'refund_policy' => 'full' ] ) );

        $this->reversal()->refunded( $order, $this->refund_of( $order, 1.0 ) );
        $this->reversal()->refunded( $order, $this->refund_of( $order, 1.0 ) );

        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REFUNDED, $this->row()['status'] );
    }

    public function test_the_refunded_status_restores_only_when_no_refund_object_exists(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );

        $this->reversal()->refunded_status( $order );

        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REFUNDED, $this->row()['status'] );
    }

    public function test_the_refunded_status_leaves_an_order_with_refund_objects_to_them(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->refund_of( $order, 7.5 );

        $this->assertSame( [], $this->reversal()->refunded_status( $order ) );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_deleted_refund_writes_the_inverse_row(): void {
        $order     = $this->redeemed_order( $this->seeded_reward() );
        $refund_id = $this->refund_of( $order, 7.5 );
        $this->reversal()->refunded( $order, $refund_id );

        wc_get_order( $refund_id )->delete( true );
        $this->reversal()->refund_deleted( $refund_id, $order->get_id() );

        $this->assertSame( [ 1000, -500, 250, -250 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
        $this->assertSame( 0, (int) $this->row()['restored_points'] );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );

        $ledger = $this->last_ledger_row();
        $this->assertSame( LedgerType::REDEEM, $ledger['type'] );
        $this->assertSame( sprintf( 'redeem:7:refund:%d:flexible_discount', $refund_id ), $ledger['idempotency_key'] );
    }

    public function test_an_uncancel_debits_again(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->cancelled( $order );

        $outcomes = $this->reversal()->uncancelled( $order );

        $this->assertSame( Outcome::AWARDED, $outcomes[0]->get_status() );
        $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
        $this->assertSame( 0, (int) $this->row()['restored_points'] );
        $this->assertSame( sprintf( 'redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $this->last_ledger_row()['idempotency_key'] );
    }

    public function test_an_uncancel_with_a_short_balance_writes_nothing(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->cancelled( $order );
        $this->ledger_service()->adjust( 7, -800, 'spent elsewhere', 1, false );

        $outcomes = $this->reversal()->uncancelled( $order );

        $this->assertSame( Outcome::SKIPPED, $outcomes[0]->get_status() );
        $this->assertSame( Result::REASON_INSUFFICIENT_BALANCE, $outcomes[0]->get_reason() );
        $this->assertSame( [ 1000, -500, 500, -800 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );
        $this->assertSame( 500, (int) $this->row()['restored_points'] );
    }

    public function test_a_second_cancel_restores_again_and_a_second_uncancel_does_not(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->cancelled( $order );
        $this->reversal()->uncancelled( $order );

        $this->reversal()->cancelled( $order );

        $this->assertSame( [ 1000, -500, 500, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( sprintf( 'refund_redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $this->last_ledger_row()['idempotency_key'] );

        $outcomes = $this->reversal()->uncancelled( $order );

        $this->assertSame( Outcome::SKIPPED, $outcomes[0]->get_status() );
        $this->assertSame( Reversal::REASON_KEY_SPENT, $outcomes[0]->get_reason() );
        $this->assertSame( [ 1000, -500, 500, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );
    }

    public function test_a_cancel_after_a_part_refund_restores_the_rest_and_an_uncancel_takes_only_that_back(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->refunded( $order, $this->refund_of( $order, 7.5 ) );

        $this->reversal()->cancelled( $order );
        $this->assertSame( [ 1000, -500, 250, 250 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );

        $this->reversal()->uncancelled( $order );
        $this->assertSame( [ 1000, -500, 250, 250, -250 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
        $this->assertSame( 250, (int) $this->row()['restored_points'] );
    }

    public function test_repeating_an_event_writes_nothing(): void {
        $order     = $this->redeemed_order( $this->seeded_reward() );
        $refund_id = $this->refund_of( $order, 7.5 );

        $this->reversal()->refunded( $order, $refund_id );
        $this->reversal()->refunded( $order, $refund_id );
        $this->assertSame( [ 1000, -500, 250 ], $this->ledger_points( 7 ) );

        $this->reversal()->cancelled( $order );
        $this->reversal()->cancelled( $order );
        $this->assertSame( [ 1000, -500, 250, 250 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->row()['restored_points'] );
    }

    /** User 7 has 9850 points and commits A (500, 5.00) and B (1500, 10.00) on an order of 100.00 that totals 85.00. */
    private function order_with_two_rewards(): \WC_Order {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b     = $this->pro_reward(
            RewardType::PERCENT_COUPON,
            1500,
            [
                'percent'      => 15,
                'max_discount' => 10,
            ]
        );
        $codes = [
            $a->get_coupon_code() => 5.0,
            $b->get_coupon_code() => 10.0,
        ];
        $order = $this->order_with_reward_lines( 7, 100.0, $codes );
        $order->set_total( 85.0 );
        $order->save();

        $outcome = $this->commit_writer()->commit_rewards( $order, array_combine( array_keys( $codes ), [ $a, $b ] ) );
        $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );

        return $order;
    }

    public function test_one_refund_restores_each_reward_row_under_its_own_key(): void {
        $order = $this->order_with_two_rewards();

        $this->reversal()->refunded( $order, $this->refund_of( $order, 42.5 ) );

        $this->assertSame( [ 9850, -500, -1500, 250, 750 ], $this->ledger_points( 7 ) );
        $this->assertSame( [ 250, 750 ], array_map( 'intval', array_column( $this->redemption_rows( 7 ), 'restored_points' ) ) );
        $this->assertSame( [ 'applied', 'applied' ], array_column( $this->redemption_rows( 7 ), 'status' ) );
    }

    public function test_a_cancel_restores_every_reward_row(): void {
        $order = $this->order_with_two_rewards();

        $this->reversal()->cancelled( $order );

        $this->assertSame( [ 9850, -500, -1500, 500, 1500 ], $this->ledger_points( 7 ) );
        $this->assertSame( [ Redemption::STATUS_CANCELLED, Redemption::STATUS_CANCELLED ], array_column( $this->redemption_rows( 7 ), 'status' ) );
        $this->assertSame( 9850, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_a_cancel_of_a_mixed_order_restores_each_row_once_under_its_own_key(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        $flexible = $this->seeded_reward();
        $a        = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $order    = $this->order_with_reward_lines(
            7,
            100.0,
            [
                $a->get_coupon_code()        => 5.0,
                $flexible->get_coupon_code() => 5.0,
            ]
        );
        $order->set_total( 90.0 );
        $order->save();
        $this->assertSame( Outcome::REDEEMED, $this->commit_writer()->commit_all( $order, [ $a->get_coupon_code() => $a ], $flexible )->get_status() );
        $this->assertSame( [ $a->get_coupon_code(), $flexible->get_coupon_code() ], array_column( $this->redemption_rows( 7 ), 'coupon_code' ) );

        $this->reversal()->cancelled( $order );
        $this->reversal()->cancelled( $order );

        $this->assertSame( [ 9850, -500, -500, 500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame(
            [
                sprintf( 'refund_redeem:7:order:%d:reward_%d', $order->get_id(), $a->get_id() ),
                sprintf( 'refund_redeem:7:order:%d:flexible_discount', $order->get_id() ),
            ],
            array_column( array_slice( $this->ledger_rows( 7 ), 3 ), 'idempotency_key' )
        );
        $this->assertSame( 9850, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_removing_a_coupon_restores_what_is_left_and_marks_the_row_removed(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );

        $outcome = $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
        $this->assertSame( 500, (int) $this->row()['restored_points'] );

        $ledger = $this->last_ledger_row();
        $this->assertSame( LedgerType::REFUND_REDEEM, $ledger['type'] );
        $this->assertSame( ReferenceType::REDEMPTION, $ledger['reference_type'] );
        $this->assertSame( (int) $this->row()['id'], (int) $ledger['reference_id'] );
        $this->assertSame( sprintf( 'refund_redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $ledger['idempotency_key'] );
    }

    public function test_a_line_removed_without_restore_leaves_the_row_for_the_cancel(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );

        $order->remove_coupon( $reward->get_coupon_code() );
        $order->save();

        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
        $this->assertSame( 0, (int) $this->row()['restored_points'] );

        $this->reversal()->cancelled( $order );

        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );
    }

    public function test_removing_a_coupon_after_a_part_refund_restores_only_the_rest(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );
        $this->reversal()->refunded( $order, $this->refund_of( $order, 6.0 ) );
        $this->assertSame( 200, (int) $this->row()['restored_points'] );

        $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

        $this->assertSame( [ 1000, -500, 200, 300 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
        $this->assertSame( 500, (int) $this->row()['restored_points'] );
    }

    public function test_no_order_event_moves_a_removed_row(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );
        $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

        $outcomes  = array_merge(
            $this->reversal()->cancelled( $order ),
            $this->reversal()->refunded_status( $order ),
            $this->reversal()->uncancelled( $order )
        );
        $refund_id = $this->refund_of( $order, 7.5 );
        $outcomes  = array_merge( $outcomes, $this->reversal()->refunded( $order, $refund_id ) );
        wc_get_order( $refund_id )->delete( true );
        $outcomes = array_merge( $outcomes, $this->reversal()->refund_deleted( $refund_id, $order->get_id() ) );

        $this->assertCount( 5, $outcomes );
        foreach ( $outcomes as $outcome ) {
            $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcome->get_reason() );
        }
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    }

    public function test_removing_the_same_coupon_twice_writes_once(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );
        $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

        $outcome = $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcome->get_reason() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertNull( $this->reversal()->coupon_removed( $order, 'summer10' ) );
    }

    public function test_a_line_gone_from_a_reused_order_is_restored_and_removed(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );

        $outcomes = $this->reversal()->lines_gone( $order, [] );

        $this->assertSame( Outcome::AWARDED, $outcomes[0]->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
        $this->assertSame( sprintf( 'refund_redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $this->last_ledger_row()['idempotency_key'] );

        $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $this->reversal()->lines_gone( $order, [] )[0]->get_reason() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_line_still_on_the_order_is_left_alone(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );

        $outcomes = $this->reversal()->lines_gone( $order, [ strtoupper( $reward->get_coupon_code() ) ] );

        $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcomes[0]->get_reason() );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
    }

    /** A row with no stored code matches no line, so it cannot be told to be gone. */
    public function test_a_row_with_no_stored_code_is_left_alone(): void {
        global $wpdb;

        $order = $this->redeemed_order( $this->seeded_reward() );
        $wpdb->update( ( new RedemptionRepository() )->table(), [ 'coupon_code' => null ], [ 'id' => (int) $this->row()['id'] ] );

        $outcomes = $this->reversal()->lines_gone( $order, [] );

        $this->assertSame( Reversal::REASON_NOTHING_TO_MOVE, $outcomes[0]->get_reason() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    }
}
