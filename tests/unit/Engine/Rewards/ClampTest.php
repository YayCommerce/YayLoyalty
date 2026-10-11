<?php
namespace YayLoyalty\Tests\Unit\Engine\Rewards;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

/**
 * The cart ceiling: how many points a cart subtotal is worth, where float error must not cost a point.
 */
final class ClampTest extends TestCase {

    private function reward( array $config = [] ): Reward {
        return new Reward( 1, 'Discount', 'discount', RewardType::FLEXIBLE_DISCOUNT, 'active', 0, null, $config + [ 'points_per_currency_unit' => 100 ] );
    }

    /** @dataProvider float_edge_subtotals */
    public function test_cart_ceiling_never_loses_a_point_to_float_error( float $subtotal, int $points ): void {
        $result = Clamp::apply( $this->reward(), 1000, 1000, $subtotal, 'USD', 2 );

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
        $result = Clamp::apply( $this->reward( [ 'points_per_currency_unit' => 3 ] ), 1000, 1000, 10.5, 'USD', 2 );

        $this->assertSame( 31, $result->get_points() );
    }

    public function test_max_percent_of_subtotal_caps_the_cart_ceiling(): void {
        $result = Clamp::apply( $this->reward( [ 'max_percent_of_subtotal' => 50 ] ), 1000, 1000, 10.0, 'USD', 2 );

        $this->assertSame( 500, $result->get_points() );
        $this->assertSame( Clamp::BOUND_CART, $result->get_bound_by() );
    }
}
