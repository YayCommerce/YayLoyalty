<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\CommitWriter;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\RewardMismatch;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** An admin repairs an order whose loyalty coupon lines and redemption rows disagree: the points move once, by the order's own figures. */
final class OrderRewardReconcileTest extends IntegrationTestCase {

    protected function tearDown(): void {
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    private function reward(): Reward {
        return $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
    }

    /** POSTs the repair of $code as an admin. */
    private function repair( \WC_Order $order, string $code ): \WP_REST_Response {
        $this->act_as_admin();

        return $this->send( 'POST', sprintf( '/yayloyalty/v1/orders/%d/reconcile-reward', $order->get_id() ), [ 'code' => $code ] );
    }

    /** An order carrying a reward's discount that no commit ever paid for. */
    private function unpaid_order( Reward $reward ): \WC_Order {
        return $this->order_with_reward_lines( 7, 100.0, [ $reward->get_coupon_code() => 5.0 ] );
    }

    /** An order that paid 500 points for a reward whose line then left it. */
    private function stale_order( Reward $reward ): \WC_Order {
        $order = $this->unpaid_order( $reward );

        $this->assertSame( Outcome::REDEEMED, $this->commit_writer()->commit_rewards( $order, [ $reward->get_coupon_code() => $reward ] )->get_status() );

        $order->remove_order_items( 'coupon' );
        $order->save();

        return wc_get_order( $order->get_id() );
    }

    public function test_debiting_an_unpaid_line_writes_one_row_and_one_debit_under_the_commits_key(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->reward();
        $order  = $this->unpaid_order( $reward );

        $response = $this->repair( $order, $reward->get_coupon_code() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 500, $response->get_data()['points'] );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );

        $rows = $this->redemption_rows( 7 );
        $this->assertCount( 1, $rows );
        $this->assertSame( Redemption::STATUS_APPLIED, $rows[0]['status'] );
        $this->assertSame( 5.0, (float) $rows[0]['value'] );
        $this->assertSame( sprintf( 'redeem:7:order:%d:reward_%d', $order->get_id(), $reward->get_id() ), $this->ledger_rows( 7 )[1]['idempotency_key'] );
        $this->assertSame( 500, (int) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
    }

    public function test_a_repaired_line_is_returned_by_a_later_cancel(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->reward();
        $order  = $this->unpaid_order( $reward );

        $this->repair( $order, $reward->get_coupon_code() );
        RedemptionService::build( new SessionIntent() )->reversal()->cancelled( $order );

        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_short_balance_refuses_the_debit_and_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 300 ) );
        $reward = $this->reward();
        $order  = $this->unpaid_order( $reward );

        $response = $this->repair( $order, $reward->get_coupon_code() );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( [ 300 ], $this->ledger_points( 7 ) );
        $this->assertCount( 0, $this->redemption_rows( 7 ) );
    }

    /** The flexible discount has no fixed cost: the points are what its line's value stands for. */
    public function test_an_unpaid_flexible_line_debits_the_points_its_value_stands_for(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $flexible = $this->seeded_reward();
        $order    = $this->order_with_points_discount( 7, 20.0, 5.0, $flexible->get_coupon_code() );

        $this->assertSame( 200, $this->repair( $order, $flexible->get_coupon_code() )->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( sprintf( 'redeem:7:order:%d:flexible_discount', $order->get_id() ), $this->ledger_rows( 7 )[1]['idempotency_key'] );
    }

    public function test_restoring_a_stale_row_returns_its_points_once(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->reward();
        $order  = $this->stale_order( $reward );

        $response = $this->repair( $order, $reward->get_coupon_code() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->redemption_rows( 7 )[0]['status'] );
        $this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );

        $this->assertSame( 409, $this->repair( $order, $reward->get_coupon_code() )->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_second_debit_is_refused(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->reward();
        $order  = $this->unpaid_order( $reward );

        $this->repair( $order, $reward->get_coupon_code() );

        $this->assertSame( 409, $this->repair( $order, $reward->get_coupon_code() )->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_line_whose_reward_is_deleted_cannot_be_priced(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->reward();
        $order  = $this->unpaid_order( $reward );
        ( new RewardRepository() )->delete( (int) $reward->get_id() );

        $this->assertNull( RewardMismatch::build()->for_order( $order )[0]['reward'] );
        $this->assertSame( 409, $this->repair( $order, $reward->get_coupon_code() )->get_status() );
        $this->assertSame( [ 1000 ], $this->ledger_points( 7 ) );
    }

    public function test_a_matching_order_and_a_cancelled_order_have_no_mismatch(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->reward();
        $order  = $this->unpaid_order( $reward );

        $this->commit_writer()->commit_rewards( $order, [ $reward->get_coupon_code() => $reward ] );
        $this->assertSame( [], RewardMismatch::build()->for_order( $order ) );

        $unpaid = $this->unpaid_order( $reward );
        $unpaid->set_status( 'cancelled' );
        $this->assertSame( [], RewardMismatch::build()->for_order( $unpaid ) );
    }

    public function test_a_visitor_is_refused(): void {
        $reward = $this->reward();
        $order  = $this->unpaid_order( $reward );

        $response = $this->send( 'POST', sprintf( '/yayloyalty/v1/orders/%d/reconcile-reward', $order->get_id() ), [ 'code' => $reward->get_coupon_code() ] );

        $this->assertContains( $response->get_status(), [ 401, 403 ] );
        $this->assertCount( 0, $this->redemption_rows( 7 ) );
    }
}
