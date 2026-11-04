<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Admin\OrderCouponRemoval;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\Reversal;
use YayLoyalty\Engine\Redemption\RewardMismatch;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** An admin's "Don't restore" is a decision: the points stay debited, and nothing later reads the row as a line that went missing. */
final class OrderCouponRemovalKeepTest extends IntegrationTestCase {

    protected function tearDown(): void {
        wp_set_current_user( 0 );
        $_POST    = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    private function reward(): Reward {
        return $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
    }

    /** User 7 has 1000 points and spends 500 on $reward. */
    private function paid_order( Reward $reward ): \WC_Order {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );

        $order = $this->order_with_reward_lines( 7, 100.0, [ $reward->get_coupon_code() => 5.0 ] );
        $this->commit_writer()->commit_rewards( $order, [ $reward->get_coupon_code() => $reward ] );

        return $order;
    }

    /** The request the dialog sends with $flag, then the removal WooCommerce's handler does. */
    private function remove_line( \WC_Order $order, string $code, string $flag ): \WC_Order {
        $this->act_as_admin();

        $_POST    = [
            $flag              => 1,
            'yayloyalty_nonce' => wp_create_nonce( 'yayloyalty-restore-coupon' ),
            'order_id'         => $order->get_id(),
            'coupon'           => $code,
        ];
        $_REQUEST = $_POST;

        $rewards = new RewardRepository();
        $removal = new OrderCouponRemoval( new Reversal( $this->ledger_service(), new RedemptionRepository(), new CustomerRepository(), $rewards ), new RedemptionRepository(), $rewards );
        $removal->before_remove();

        $order = wc_get_order( $order->get_id() );

        foreach ( array_keys( $order->get_items( 'coupon' ) ) as $item_id ) {
            $order->remove_item( $item_id );
        }

        $order->save();

        return wc_get_order( $order->get_id() );
    }

    public function test_a_kept_line_is_not_a_mismatch_and_moves_no_points(): void {
        $reward = $this->reward();
        $order  = $this->remove_line( $this->paid_order( $reward ), $reward->get_coupon_code(), 'yayloyalty_keep' );

        $this->assertSame( [], $order->get_coupon_codes() );
        $this->assertSame( [ $reward->get_coupon_code() ], Reversal::kept_codes( $order ) );
        $this->assertSame( [], RewardMismatch::build()->for_order( $order ) );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
    }

    public function test_a_retry_does_not_restore_a_kept_line(): void {
        $reward = $this->reward();
        $order  = $this->remove_line( $this->paid_order( $reward ), $reward->get_coupon_code(), 'yayloyalty_keep' );

        RedemptionService::build( new SessionIntent() )->commit( $order );

        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_APPLIED, $this->redemption_rows( 7 )[0]['status'] );
    }

    /** Only the dialog's "Don't restore" remembers the code: Restore returns the points, as before. */
    public function test_restore_remembers_nothing_and_returns_the_points(): void {
        $reward = $this->reward();
        $order  = $this->remove_line( $this->paid_order( $reward ), $reward->get_coupon_code(), 'yayloyalty_restore' );

        $this->assertSame( [], Reversal::kept_codes( $order ) );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
    }

    /** A line that left outside the dialog: nobody decided about its points, so an admin is still asked. */
    public function test_a_line_gone_another_way_is_still_a_mismatch(): void {
        $reward = $this->reward();
        $order  = $this->paid_order( $reward );

        $order->remove_order_items( 'coupon' );
        $order->save();

        $found = RewardMismatch::build()->for_order( wc_get_order( $order->get_id() ) );

        $this->assertCount( 1, $found );
        $this->assertSame( RewardMismatch::KIND_STALE_ROW, $found[0]['kind'] );
    }
}
