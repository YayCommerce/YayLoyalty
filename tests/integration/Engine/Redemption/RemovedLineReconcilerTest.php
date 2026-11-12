<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\RemovedLineReconciler;
use YayLoyalty\Engine\Redemption\Reversal;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * A loyalty line that leaves an order by any route returns its points when the request settles, unless the admin waived it.
 * The plugin's own reconciler is registered too and settles at the PHPUnit process's shutdown; by then each row it
 * queued is `removed` or its order is gone, so it writes nothing.
 */
final class RemovedLineReconcilerTest extends IntegrationTestCase {

    private RemovedLineReconciler $reconciler;

    protected function setUp(): void {
        parent::setUp();

        $this->reconciler = new RemovedLineReconciler( $this->reversal(), new RedemptionRepository(), new RewardRepository() );
        $this->reconciler->register();
    }

    protected function tearDown(): void {
        remove_action( 'woocommerce_before_delete_order_item', [ $this->reconciler, 'on_item_deleted' ] );
        remove_action( 'woocommerce_before_delete_order', [ $this->reconciler, 'on_order_deleted' ] );
        remove_action( 'shutdown', [ $this->reconciler, 'settle' ], 0 );

        parent::tearDown();
    }

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

    /**
     * User 7 spends 500 on a $5 off reward line of an order of 100.00. The REST cases use it: WC_Coupon loads a Pro
     * reward's code without a cart.
     *
     * @return array{0: \WC_Order, 1: Reward}
     */
    private function order_with_committed_reward(): array {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $order  = $this->order_with_reward_lines( 7, 100.0, [ $reward->get_coupon_code() => 5.0 ] );
        $this->assertSame( Outcome::REDEEMED, $this->commit_writer()->commit_rewards( $order, [ $reward->get_coupon_code() => $reward ] )->get_status() );

        return [ $order, $reward ];
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

    /** @return string[] */
    private function notes( \WC_Order $order ): array {
        return array_map( static fn( $note ): string => $note->content, wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
    }

    public function test_remove_coupon_restores_at_settle(): void {
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );

        $order->remove_coupon( $reward->get_coupon_code() );
        $order->save();
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );

        $this->reconciler->settle();

        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( sprintf( 'refund_redeem:7:redemption:%d:flexible_discount', (int) $this->row()['id'] ), $this->last_ledger_row()['idempotency_key'] );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
        $this->assertContains( sprintf( 'Cart discount removed — %s restored.', SettingsHelper::points_text( 500 ) ), $this->notes( $order ) );

        $this->reconciler->settle();
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_rest_update_that_keeps_the_line_restores_nothing(): void {
        $this->act_as_admin();
        list( $order, $reward ) = $this->order_with_committed_reward();

        $response = $this->send( 'PUT', '/wc/v3/orders/' . $order->get_id(), [ 'coupon_lines' => [ [ 'code' => $reward->get_coupon_code() ] ] ] );
        $this->reconciler->settle();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ $reward->get_coupon_code() ], wc_get_order( $order->get_id() )->get_coupon_codes() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->row()['status'] );
    }

    public function test_a_rest_update_that_drops_the_line_restores_it(): void {
        $this->act_as_admin();
        list( $order ) = $this->order_with_committed_reward();

        $response = $this->send( 'PUT', '/wc/v3/orders/' . $order->get_id(), [ 'coupon_lines' => [] ] );
        $this->reconciler->settle();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
    }

    public function test_a_waived_line_is_final(): void {
        $this->act_as_admin();
        $reward = $this->seeded_reward();
        $order  = $this->redeemed_order( $reward );

        $this->reconciler->waive( $order->get_id(), $reward->get_coupon_code() );
        $order->remove_coupon( $reward->get_coupon_code() );
        $order->save();
        $this->reconciler->settle();

        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->row()['status'] );
        $this->assertSame( 0, (int) $this->row()['restored_points'] );
        $this->assertContains( sprintf( 'Cart discount removed — points not restored, as chosen by %s.', wp_get_current_user()->display_name ), $this->notes( $order ) );
    }

    public function test_a_deleted_order_restores_nothing(): void {
        $order = $this->redeemed_order( $this->seeded_reward() );

        $order->delete( true );
        $this->reconciler->settle();

        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    }
}
