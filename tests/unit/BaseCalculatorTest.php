<?php
namespace YayLoyalty\Tests\Unit;

use YayLoyalty\Engine\Points\BaseCalculator;

/**
 * Plan §6 cases 1–13.
 */
final class BaseCalculatorTest extends EngineTestCase {

    private function compute( array $lines, array $config = [], float $loyalty = 0.0, float $base_rate = 1.0 ): float {
        return ( new BaseCalculator() )->compute( $this->purchase_campaign( $config ), $this->context( $lines, $loyalty, [], $base_rate ) );
    }

    public function test_1_single_item(): void {
        $this->assertSame( 100.0, $this->compute( [ $this->line( 100 ) ] ) );
    }

    public function test_2_two_items(): void {
        $this->assertSame( 150.0, $this->compute( [ $this->line( 100 ), $this->line( 50, null, 11 ) ] ) );
    }

    public function test_3_real_coupon_after_discounts(): void {
        $this->assertSame( 80.0, $this->compute( [ $this->line( 100, 80 ) ] ) );
    }

    public function test_4_real_coupon_before_discounts(): void {
        $this->assertSame( 100.0, $this->compute( [ $this->line( 100, 80 ) ], [ 'base' => 'before_discounts' ] ) );
    }

    public function test_5_loyalty_coupon_added_back(): void {
        $this->assertSame( 100.0, $this->compute( [ $this->line( 100, 90 ) ], [], 10.0 ) );
    }

    public function test_6_real_and_loyalty_coupon(): void {
        $this->assertSame( 80.0, $this->compute( [ $this->line( 100, 70 ) ], [], 10.0 ) );
    }

    public function test_7_excluded_product(): void {
        $lines = [ $this->line( 100, null, 10 ), $this->line( 50, null, 11 ) ];

        $this->assertSame( 50.0, $this->compute( $lines, [ 'excluded_product_ids' => [ 10 ] ] ) );
    }

    public function test_8_excluded_category(): void {
        $lines = [ $this->line( 100, null, 10, [ 3 ] ), $this->line( 50, null, 11, [ 4 ] ) ];

        $this->assertSame( 50.0, $this->compute( $lines, [ 'excluded_category_ids' => [ 3 ] ] ) );
    }

    public function test_9_everything_excluded(): void {
        $this->assertSame( 0.0, $this->compute( [ $this->line( 100, null, 10 ) ], [ 'excluded_product_ids' => [ 10 ] ] ) );
    }

    public function test_10_shipping_is_not_a_line_item(): void {
        // Shipping never reaches the Context as a line, so there is nothing to exclude.
        $this->assertSame( 100.0, $this->compute( [ $this->line( 100 ) ] ) );
    }

    public function test_11_base_rate(): void {
        $this->assertEqualsWithDelta( 110.0, $this->compute( [ $this->line( 100 ) ], [], 0.0, 1.1 ), 0.0001 );
    }

    public function test_12_include_tax(): void {
        $this->assertSame( 108.0, $this->compute( [ $this->line( 100, null, 10, [], 8.0 ) ], [ 'include_tax' => true ] ) );
    }

    public function test_13_loyalty_coupon_added_back_only_for_included_share(): void {
        // $100 excluded + $100 included, $10 loyalty coupon split evenly → add back $5.
        $lines = [ $this->line( 100, 95, 10 ), $this->line( 100, 95, 11 ) ];

        $this->assertSame( 100.0, $this->compute( $lines, [ 'excluded_product_ids' => [ 10 ] ], 10.0 ) );
    }

    public function test_base_amount_filter_fires_with_documented_signature(): void {
        $seen = null;

        add_filter(
            'yayloyalty_earning_base_amount',
            static function ( $base, $campaign, $ctx ) use ( &$seen ) {
                $seen = [ $base, get_class( $campaign ), get_class( $ctx ) ];

                return $base + 1;
            }
        );

        $this->assertSame( 101.0, $this->compute( [ $this->line( 100 ) ] ) );
        $this->assertSame( [ 100.0, 'YayLoyalty\Models\Campaign', 'YayLoyalty\Engine\Points\Context' ], $seen );
    }
}
