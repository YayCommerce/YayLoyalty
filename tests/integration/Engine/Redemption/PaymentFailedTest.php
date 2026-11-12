<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\Reversal;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** A failed payment returns the redeemed points and paying again takes them back, as often as the cycle repeats. */
final class PaymentFailedTest extends IntegrationTestCase {

    private function reversal(): Reversal {
        return new Reversal( $this->ledger_service(), new RedemptionRepository(), new CustomerRepository(), new RewardRepository(), new LedgerRepository() );
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

    /** @return array<string, mixed> */
    private function row(): array {
        return $this->redemption_rows( 7 )[0];
    }

    /** @return array<string, mixed> */
    private function last_ledger_row(): array {
        $rows = $this->ledger_rows( 7 );

        return end( $rows );
    }

    /** The checkout's `redeem` row. */
    private function redeem_row_id(): int {
        return (int) $this->ledger_rows( 7 )[1]['id'];
    }

    public function test_a_failed_payment_restores_and_marks_the_row_unpaid(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );

        $outcomes = $this->reversal()->payment_failed( $order );

        $this->assertSame( Outcome::AWARDED, $outcomes[0]->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_UNPAID, $this->row()['status'] );
        $this->assertSame( 500, (int) $this->row()['restored_points'] );
        $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( sprintf( 'refund_redeem:7:ledger:%d:flexible_discount', $this->redeem_row_id() ), $this->last_ledger_row()['idempotency_key'] );
    }

    public function test_a_repeated_failure_writes_nothing(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->payment_failed( $order );

        $outcomes = $this->reversal()->payment_failed( $order );

        $this->assertSame( Outcome::SKIPPED, $outcomes[0]->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_retry_debits_again_under_a_chained_key(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->payment_failed( $order );
        $restore_id = (int) $this->last_ledger_row()['id'];

        $this->reversal()->payment_retried( $order, false );

        $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
        $this->assertSame( 0, (int) $this->row()['restored_points'] );
        $this->assertSame( sprintf( 'redeem:7:ledger:%d:flexible_discount', $restore_id ), $this->last_ledger_row()['idempotency_key'] );
    }

    public function test_two_failed_cycles_each_write_their_own_rows(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );

        $this->reversal()->payment_failed( $order );
        $this->reversal()->payment_retried( $order, false );
        $this->reversal()->payment_failed( $order );
        $this->reversal()->payment_retried( $order, false );

        $this->assertSame( [ 1000, -500, 500, -500, 500, -500 ], $this->ledger_points( 7 ) );
        $keys = array_column( $this->ledger_rows( 7 ), 'idempotency_key' );
        $this->assertSame( $keys, array_values( array_unique( $keys ) ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_retrying_twice_debits_once(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->payment_failed( $order );

        $this->reversal()->payment_retried( $order, false );
        $outcomes = $this->reversal()->payment_retried( $order, false );

        $this->assertSame( Outcome::SKIPPED, $outcomes[0]->get_status() );
        $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_short_balance_refuses_a_strict_retry_and_writes_nothing(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->payment_failed( $order );
        $this->ledger_service()->adjust( 7, -600, 'spent elsewhere', 1 );

        $outcomes = $this->reversal()->payment_retried( $order, false );

        $this->assertSame( Result::REASON_INSUFFICIENT_BALANCE, $outcomes[0]->get_reason() );
        $this->assertSame( 400, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( Redemption::STATUS_UNPAID, $this->row()['status'] );
    }

    public function test_a_lenient_retry_goes_negative(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->payment_failed( $order );
        $this->ledger_service()->adjust( 7, -600, 'spent elsewhere', 1 );

        $this->reversal()->payment_retried( $order, true );

        $this->assertSame( -100, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
    }

    public function test_a_cancel_of_an_unpaid_order_writes_nothing_and_an_uncancel_debits_once(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->reversal()->payment_failed( $order );

        $this->reversal()->cancelled( $order );

        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_CANCELLED, $this->row()['status'] );

        $this->reversal()->uncancelled( $order );

        $this->assertSame( [ 1000, -500, 500, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( sprintf( 'redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $this->last_ledger_row()['idempotency_key'] );
    }

    public function test_has_unpaid_reads_the_rows(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );
        $this->assertFalse( $this->reversal()->has_unpaid( $order ) );

        $this->reversal()->payment_failed( $order );

        $this->assertTrue( $this->reversal()->has_unpaid( $order ) );
    }
}
