<?php
namespace YayLoyalty\Tests\Unit\Rewards;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

/** The single clamp: every ceiling, which one is reported, whole-unit steps. Pure — no bootstrap, no WordPress. */
final class ClampTest extends TestCase {

    /**
     * @param array<string, mixed> $overrides
     */
    private function reward( array $overrides = [] ): Reward {
        $config = array_merge(
            [
                'currency'                 => 'USD',
                'points_per_currency_unit' => 100,
                'min_points'               => 100,
                'max_points_per_order'     => null,
                'max_percent_of_subtotal'  => null,
                'increment'                => 100,
            ],
            $overrides
        );

        return new Reward( 1, 'Cart discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, 'active', 0, null, $config );
    }

    public function test_a_request_within_every_ceiling_stands_unbound(): void {
        $quote = Clamp::apply( $this->reward(), 500, 1000, 80.0, 'USD' );

        $this->assertSame( 500, $quote->get_points_requested() );
        $this->assertSame( 500, $quote->get_points() );
        $this->assertSame( 5.0, $quote->get_value() );
        $this->assertSame( Clamp::BOUND_NONE, $quote->get_bound_by() );
        $this->assertSame( 100.0, $quote->get_rate_snapshot() );
    }

    public function test_balance_binds_and_is_reported(): void {
        $quote = Clamp::apply( $this->reward(), 1000, 350, 80.0, 'USD' );

        $this->assertSame( 300, $quote->get_points() );
        $this->assertSame( Clamp::BOUND_BALANCE, $quote->get_bound_by() );
    }

    public function test_reward_max_binds_and_is_reported(): void {
        $quote = Clamp::apply( $this->reward( [ 'max_points_per_order' => 400 ] ), 1000, 5000, 80.0, 'USD' );

        $this->assertSame( 400, $quote->get_points() );
        $this->assertSame( Clamp::BOUND_REWARD_MAX, $quote->get_bound_by() );
    }

    public function test_cart_subtotal_after_other_coupons_is_a_ceiling(): void {
        $quote = Clamp::apply( $this->reward(), 5000, 10000, 12.5, 'USD' );

        $this->assertSame( 1200, $quote->get_points() );
        $this->assertSame( 12.0, $quote->get_value() );
        $this->assertSame( Clamp::BOUND_CART, $quote->get_bound_by() );
    }

    public function test_max_percent_of_subtotal_tightens_the_cart_ceiling(): void {
        $quote = Clamp::apply( $this->reward( [ 'max_percent_of_subtotal' => 50 ] ), 5000, 10000, 80.0, 'USD' );

        $this->assertSame( 4000, $quote->get_points() );
        $this->assertSame( Clamp::BOUND_CART, $quote->get_bound_by() );
    }

    public function test_no_cart_means_no_cart_ceiling(): void {
        $quote = Clamp::apply( $this->reward(), 5000, 10000, null, 'USD' );

        $this->assertSame( 5000, $quote->get_points() );
        $this->assertSame( Clamp::BOUND_NONE, $quote->get_bound_by() );
    }

    public function test_steps_are_whole_currency_units_by_default_so_value_has_no_remainder(): void {
        $quote = Clamp::apply( $this->reward(), 250, 10000, 80.0, 'USD' );

        $this->assertSame( 200, $quote->get_points() );
        $this->assertSame( 2.0, $quote->get_value() );
        $this->assertSame( Clamp::BOUND_STEP, $quote->get_bound_by() );
    }

    public function test_step_rounding_does_not_hide_the_ceiling_that_bound(): void {
        $quote = Clamp::apply( $this->reward(), 1000, 350, 80.0, 'USD' );

        $this->assertSame( 300, $quote->get_points() );
        $this->assertSame( Clamp::BOUND_BALANCE, $quote->get_bound_by() );
    }

    public function test_below_min_points_applies_nothing_and_says_why(): void {
        $quote = Clamp::apply( $this->reward(), 50, 10000, 80.0, 'USD' );

        $this->assertTrue( $quote->is_allowed() );
        $this->assertSame( 0, $quote->get_points() );
        $this->assertSame( 0.0, $quote->get_value() );
        $this->assertSame( Clamp::BOUND_REWARD_MIN, $quote->get_bound_by() );
    }

    public function test_a_low_balance_is_reported_over_the_minimum(): void {
        $quote = Clamp::apply( $this->reward(), 500, 50, 80.0, 'USD' );

        $this->assertSame( 0, $quote->get_points() );
        $this->assertSame( Clamp::BOUND_BALANCE, $quote->get_bound_by() );
    }

    public function test_the_tightest_ceiling_is_the_one_reported(): void {
        $quote = Clamp::apply( $this->reward( [ 'max_points_per_order' => 900 ] ), 5000, 2000, 7.0, 'USD' );

        $this->assertSame( 700, $quote->get_points() );
        $this->assertSame( Clamp::BOUND_CART, $quote->get_bound_by() );
    }

    public function test_float_subtotals_do_not_lose_a_point_to_binary_rounding(): void {
        $quote = Clamp::apply(
            $this->reward(
                [
                    'min_points' => 0,
                    'increment'  => 1,
                ]
            ),
            100,
            100,
            0.29,
            'USD'
        );

        $this->assertSame( 29, $quote->get_points() );
    }

    public function test_zero_or_negative_requests_apply_nothing_and_bind_nothing(): void {
        $this->assertSame( 0, Clamp::apply( $this->reward(), 0, 1000, 80.0, 'USD' )->get_points() );
        $this->assertSame( Clamp::BOUND_NONE, Clamp::apply( $this->reward(), 0, 1000, 80.0, 'USD' )->get_bound_by() );
        $this->assertSame( 0, Clamp::apply( $this->reward(), -5, 1000, 80.0, 'USD' )->get_points_requested() );
    }

    public function test_a_reward_without_a_rate_is_denied_not_quoted(): void {
        $quote = Clamp::apply( $this->reward( [ 'points_per_currency_unit' => 0 ] ), 100, 1000, 80.0, 'USD' );

        $this->assertFalse( $quote->is_allowed() );
        $this->assertSame( Result::REASON_NO_RATE, $quote->get_reason() );
    }
}
