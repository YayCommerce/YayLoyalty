<?php
namespace YayTests\Unit\Engine\Redemption;

use Brain\Monkey\Functions;
use YayLoyalty\Engine\Redemption\RewardCoupons;
use YayTests\Unit\TestCase;

/**
 * @group baseline
 */
final class RewardCouponsTest extends TestCase {

	public function test_scales_a_capped_percent_down_so_it_takes_off_the_cap() {
		$this->assertSame( 4.0, RewardCoupons::percent_for_cap( 10.0, 50.0, 20.0 ) );
	}

	public function test_floors_the_scaled_percent_so_float_noise_never_passes_the_cap() {
		$percent = RewardCoupons::percent_for_cap( 7.0, 33.33, 5.0 );

		$this->assertSame( 1.050105, $percent );
		$this->assertLessThanOrEqual( 5.0, 33.33 * $percent / 7.0 );
	}

	public function test_leaves_the_percent_alone_under_the_cap_or_with_nothing_to_discount() {
		$this->assertSame( 10.0, RewardCoupons::percent_for_cap( 10.0, 50.0, 60.0 ) );
		$this->assertSame( 10.0, RewardCoupons::percent_for_cap( 10.0, 0.0, 5.0 ) );
	}

	public function test_converts_a_fixed_coupon_and_its_limits_to_the_charged_currency_floored() {
		Functions\when( 'wc_get_price_decimals' )->justReturn( 2 );

		$props = RewardCoupons::in_currency(
			[ 'discount_type' => 'fixed_cart', 'amount' => 10.0, 'minimum_amount' => 33.33, 'max_discount' => null ],
			1.5
		);

		$this->assertSame( 15.0, $props['amount'] );
		$this->assertSame( 49.99, $props['minimum_amount'] );
		$this->assertNull( $props['max_discount'] );
	}

	public function test_keeps_a_percent_a_percent_and_converts_only_its_money_limits() {
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );

		$props = RewardCoupons::in_currency(
			[ 'discount_type' => 'percent', 'amount' => 15.0, 'minimum_amount' => 50.0, 'max_discount' => 20.0 ],
			24000.0
		);

		$this->assertSame( 15.0, $props['amount'] );
		$this->assertSame( 1200000.0, $props['minimum_amount'] );
		$this->assertSame( 480000.0, $props['max_discount'] );
	}

	public function test_base_currency_or_an_unusable_rate_changes_nothing() {
		$props = [ 'discount_type' => 'fixed_cart', 'amount' => 10.0 ];

		$this->assertSame( $props, RewardCoupons::in_currency( $props, 1.0 ) );
		$this->assertSame( $props, RewardCoupons::in_currency( $props, 0.0 ) );
	}
}
