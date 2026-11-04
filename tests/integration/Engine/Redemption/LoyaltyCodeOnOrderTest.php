<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\Reversal;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** CouponProvider's guard: a loyalty code goes on an order only while that order has an `applied` redemption row for it. */
final class LoyaltyCodeOnOrderTest extends IntegrationTestCase {

    private const REFUSED = 'This loyalty discount can only be applied by the customer at checkout.';

    private function reversal(): Reversal {
        return new Reversal( $this->ledger_service(), new RedemptionRepository(), new CustomerRepository(), new RewardRepository() );
    }

    /**
     * User 7 spends 500 on a $5 off reward line of an order of 100.00.
     *
     * @return array{0: \WC_Order, 1: \YayLoyalty\Models\Reward}
     */
    private function order_with_committed_reward(): array {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $reward = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $order  = $this->order_with_reward_lines( 7, 100.0, [ $reward->get_coupon_code() => 5.0 ] );
        $this->assertSame( Outcome::REDEEMED, $this->commit_writer()->commit_rewards( $order, [ $reward->get_coupon_code() => $reward ] )->get_status() );

        return [ $order, $reward ];
    }

    public function test_a_loyalty_code_the_order_did_not_debit_is_refused(): void {
        $order = $this->order_with_reward_lines( 7, 20.0, [] );

        $result = $order->apply_coupon( 'yayloyalty-abc12345' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( self::REFUSED, $result->get_error_message() );
        $this->assertSame( [], wc_get_order( $order->get_id() )->get_coupon_codes() );
    }

    public function test_a_removed_rows_code_is_refused(): void {
        list( $order, $reward ) = $this->order_with_committed_reward();
        $this->reversal()->coupon_removed( $order, $reward->get_coupon_code() );
        $order->remove_coupon( $reward->get_coupon_code() );
        $order->save();

        $result = $order->apply_coupon( $reward->get_coupon_code() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( self::REFUSED, $result->get_error_message() );
    }

    public function test_the_code_of_an_applied_row_is_allowed(): void {
        list( $order, $reward ) = $this->order_with_committed_reward();
        $order->remove_coupon( $reward->get_coupon_code() );
        $order->save();

        $this->assertTrue( $order->apply_coupon( $reward->get_coupon_code() ) );
        $this->assertSame( [ $reward->get_coupon_code() ], $order->get_coupon_codes() );
    }

    public function test_recalculating_keeps_loyalty_lines_and_amounts(): void {
        list( $order ) = $this->order_with_committed_reward();
        $before        = self::coupon_discounts( $order );

        $order->recalculate_coupons();

        $this->assertSame( $before, self::coupon_discounts( $order ) );
        // The fixture never ran totals, so only the recalculated total is checked.
        $this->assertSame( 5.0, (float) $order->get_discount_total() );
    }

    /** @return array<string, float> Each coupon line's code => its discount. */
    private static function coupon_discounts( \WC_Order $order ): array {
        $discounts = [];

        foreach ( $order->get_items( 'coupon' ) as $item ) {
            $discounts[ $item->get_code() ] = (float) $item->get_discount();
        }

        return $discounts;
    }
}
