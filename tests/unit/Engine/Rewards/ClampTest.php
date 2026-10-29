<?php
namespace YayLoyalty\Tests\Unit\Engine\Rewards;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;

/** Clamp: every ceiling, the step, the minimum, and the whole-coin rule that keeps the debit equal to the discount. */
final class ClampTest extends TestCase {

    /** The seeded flexible discount reward: 100 points per currency unit, minimum 100, step 100. */
    private function reward( array $config = [] ): Reward {
        return new Reward(
            9,
            'Cart discount',
            'cart-discount',
            RewardType::FLEXIBLE_DISCOUNT,
            Status::ACTIVE,
            0,
            null,
            array_merge( Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ), $config ),
            [],
            null,
            null,
            true,
            null,
            null,
            '2026-06-15 12:00:00',
            '2026-06-15 12:00:00'
        );
    }

    /** The same reward spent a point at a time, so only the ceilings limit it. */
    private function unstepped_reward( array $config = [] ): Reward {
        return $this->reward(
            array_merge(
                [
                    'min_points' => 1,
                    'increment'  => 1,
                ],
                $config
            )
        );
    }

    public function test_a_request_within_every_limit_stands(): void {
        $result = Clamp::apply( $this->reward(), 500, 1000, 50.0, 'USD', 2 );

        $this->assertTrue( $result->is_allowed() );
        $this->assertSame( 500, $result->get_points() );
        $this->assertSame( 5.0, $result->get_value() );
        $this->assertSame( Clamp::BOUND_NONE, $result->get_bound_by() );
    }

    public function test_the_balance_caps_the_request(): void {
        $result = Clamp::apply( $this->reward(), 5000, 1200, null, 'USD', 2 );

        $this->assertSame( 1200, $result->get_points() );
        $this->assertSame( Clamp::BOUND_BALANCE, $result->get_bound_by() );
    }

    public function test_the_per_order_cap_caps_the_request(): void {
        $result = Clamp::apply( $this->reward( [ 'max_points_per_order' => 300 ] ), 500, 1000, null, 'USD', 2 );

        $this->assertSame( 300, $result->get_points() );
        $this->assertSame( Clamp::BOUND_REWARD_MAX, $result->get_bound_by() );
    }

    public function test_the_subtotal_caps_the_request_and_the_step_rounds_it_down(): void {
        $result = Clamp::apply( $this->reward(), 500, 1000, 2.5, 'USD', 2 );

        $this->assertSame( 200, $result->get_points() );
        $this->assertSame( 2.0, $result->get_value() );
        $this->assertSame( Clamp::BOUND_CART, $result->get_bound_by() );
    }

    public function test_the_percent_of_subtotal_caps_the_request(): void {
        $result = Clamp::apply( $this->reward( [ 'max_percent_of_discountable' => 50 ] ), 800, 1000, 10.0, 'USD', 2 );

        $this->assertSame( 500, $result->get_points() );
        $this->assertSame( Clamp::BOUND_CART, $result->get_bound_by() );
    }

    /** @dataProvider float_edge_subtotals */
    public function test_cart_ceiling_never_loses_a_point_to_float_error( float $subtotal, int $points ): void {
        $result = Clamp::apply( $this->unstepped_reward(), 1000, 1000, $subtotal, 'USD', 2 );

        $this->assertSame( $points, $result->get_points() );
        $this->assertSame( Clamp::BOUND_CART, $result->get_bound_by() );
    }

    public function float_edge_subtotals(): array {
        return [
            '0.29 x 100' => [ 0.29, 29 ],
            '0.57 x 100' => [ 0.57, 57 ],
            '1.15 x 100' => [ 1.15, 115 ],
        ];
    }

    public function test_cart_ceiling_rounds_a_fraction_down(): void {
        $result = Clamp::apply( $this->unstepped_reward( [ 'points_per_currency_unit' => 3 ] ), 1000, 1000, 10.5, 'USD', 2 );

        $this->assertSame( 31, $result->get_points() );
    }

    public function test_a_request_off_the_step_rounds_down(): void {
        $result = Clamp::apply( $this->reward(), 250, 1000, null, 'USD', 2 );

        $this->assertSame( 200, $result->get_points() );
        $this->assertSame( Clamp::BOUND_STEP, $result->get_bound_by() );
    }

    public function test_a_request_below_the_minimum_applies_nothing(): void {
        $result = Clamp::apply( $this->reward(), 50, 1000, null, 'USD', 2 );

        $this->assertTrue( $result->is_allowed() );
        $this->assertSame( 0, $result->get_points() );
        $this->assertSame( Clamp::BOUND_REWARD_MIN, $result->get_bound_by() );
    }

    public function test_a_balance_below_the_minimum_reports_the_balance(): void {
        $result = Clamp::apply( $this->reward(), 500, 50, null, 'USD', 2 );

        $this->assertSame( 0, $result->get_points() );
        $this->assertSame( Clamp::BOUND_BALANCE, $result->get_bound_by() );
    }

    public function test_points_that_buy_less_than_one_coin_apply_nothing(): void {
        $fine = $this->reward(
            [
                'points_per_currency_unit' => 1000,
                'min_points'               => 1,
                'increment'                => 1,
            ]
        );

        $result = Clamp::apply( $fine, 5, 1000, null, 'USD', 2 );

        $this->assertSame( 0, $result->get_points() );
        $this->assertSame( Clamp::BOUND_REWARD_MIN, $result->get_bound_by() );
    }

    public function test_points_beyond_a_whole_coin_are_not_taken(): void {
        $fine = $this->reward(
            [
                'points_per_currency_unit' => 1000,
                'min_points'               => 1,
                'increment'                => 1,
            ]
        );

        $result = Clamp::apply( $fine, 15, 1000, null, 'USD', 2 );

        $this->assertSame( 10, $result->get_points() );
        $this->assertSame( 0.01, $result->get_value() );
        $this->assertSame( Clamp::BOUND_STEP, $result->get_bound_by() );
    }

    public function test_points_for_is_the_inverse_of_the_applied_value(): void {
        $this->assertSame( 500, Clamp::points_for( 5.0, $this->reward() ) );
        $this->assertSame( 100, Clamp::points_for( 0.66, $this->reward( [ 'points_per_currency_unit' => 150 ] ) ) );
        $this->assertSame( 0, Clamp::points_for( 0.0, $this->reward() ) );
    }

    public function test_a_currency_rate_prices_points_in_the_charged_currency(): void {
        // 1 point = 25,000 in base; the shopper pays in a currency at 0.00004 per base unit, so 1 point = 1.00.
        $reward = $this->unstepped_reward( [ 'points_per_currency_unit' => 0.00004 ] );

        $result = Clamp::apply( $reward, 1, 10, null, 'USD', 2, 0.00004 );

        $this->assertTrue( $result->is_allowed() );
        $this->assertSame( 1, $result->get_points() );
        $this->assertSame( 1.0, $result->get_value() );
        $this->assertSame( 1.0, $result->get_rate_snapshot() );
        $this->assertSame( 1, Clamp::points_for( 1.0, $reward, 0.00004 ) );
    }

    public function test_the_value_still_floors_to_the_currency_decimals(): void {
        $result = Clamp::apply( $this->unstepped_reward( [ 'points_per_currency_unit' => 3 ] ), 2, 10, null, 'USD', 2 );

        $this->assertSame( 2, $result->get_points() );
        $this->assertSame( 0.66, $result->get_value() );
    }

    /** USD over a VND base at 25,000 ₫ = $1: 100 points per ₫ is 25,000 points per cent, so a step of 100 buys nothing. */
    public function test_the_effective_step_buys_whole_coins(): void {
        $this->assertSame( 25000, Clamp::effective_step( $this->reward(), 2, 1 / 25000 ) );
        $this->assertSame( 100, Clamp::effective_step( $this->reward(), 2 ) );
    }

    public function test_a_reward_without_a_rate_is_denied(): void {
        $result = Clamp::apply( $this->reward( [ 'points_per_currency_unit' => 0 ] ), 500, 1000, null, 'USD', 2 );

        $this->assertFalse( $result->is_allowed() );
        $this->assertSame( Result::REASON_NO_RATE, $result->get_reason() );
    }
}
