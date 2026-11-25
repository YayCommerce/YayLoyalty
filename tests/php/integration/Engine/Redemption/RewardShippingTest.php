<?php
namespace YayTests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Redemption\RewardShipping;
use YayLoyalty\Enums\RewardType;


require_once __DIR__ . '/FreeRewardTestCase.php';

/**
 * A picked free-shipping reward sets every rate to 0 while its coupon is applied, and shipping costs what it did once
 * the reward is gone, even from WooCommerce's cached rates.
 *
 * @group P-10
 */
final class RewardShippingTest extends FreeRewardTestCase {

	/** @var int */
	private $instance_id;

	public function set_up() {
		parent::set_up();
		$zone              = \WC_Shipping_Zones::get_zone( 0 );
		$this->instance_id = (int) $zone->add_shipping_method( 'flat_rate' );
		update_option(
			'woocommerce_flat_rate_' . $this->instance_id . '_settings',
			[
				'title'      => 'Flat rate',
				'tax_status' => 'none',
				'cost'       => '7',
			]
		);
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		WC()->shipping()->load_shipping_methods();
		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_state( 'CA' );
		WC()->customer->set_shipping_postcode( '90210' );
	}

	public function tear_down() {
		\WC_Shipping_Zones::get_zone( 0 )->delete_shipping_method( $this->instance_id );
		parent::tear_down();
	}

	public function test_free_shipping_zeroes_every_rate_and_restores_it_from_the_cached_original() {
		$reward = $this->reward( RewardType::FREE_SHIPPING, [] );
		WC()->cart->add_to_cart( $this->product( 20 )->get_id(), 1 );
		WC()->cart->calculate_totals();
		$this->assertSame( 7.0, (float) WC()->cart->get_shipping_total() );
		$this->assertSame( 7.0, RewardShipping::cart_value() );

		$this->pick( [ (int) $reward->get_id() ] );

		$this->assertContains( $reward->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertSame( 0.0, (float) WC()->cart->get_shipping_total() );
		$this->assertSame( 20.0, (float) WC()->cart->get_total( 'edit' ) );
		$this->assertSame( 7.0, RewardShipping::cart_value() );

		$this->pick( [] );

		$this->assertSame( 7.0, (float) WC()->cart->get_shipping_total() );
		$this->assertSame( 27.0, (float) WC()->cart->get_total( 'edit' ) );
	}

	/** @group P-11 */
	public function test_free_shipping_is_dropped_on_the_run_its_rate_becomes_free() {
		$reward = $this->reward( RewardType::FREE_SHIPPING, [] );
		WC()->cart->add_to_cart( $this->product( 20 )->get_id(), 1 );
		WC()->cart->calculate_totals();
		$this->pick( [ (int) $reward->get_id() ] );
		$this->assertContains( $reward->get_coupon_code(), WC()->cart->get_applied_coupons() );

		update_option(
			'woocommerce_flat_rate_' . $this->instance_id . '_settings',
			[
				'title'      => 'Flat rate',
				'tax_status' => 'none',
				'cost'       => '0',
			]
		);
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		WC()->shipping()->load_shipping_methods();
		WC()->cart->calculate_totals();

		$this->assertNotContains( $reward->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertNotContains( (int) $reward->get_id(), $this->intent->rewards() );
		$this->assertSame( [ $reward->get_name() ], $this->intent->dropped() );
	}

	public function test_a_second_free_shipping_reward_is_not_applied() {
		$first  = $this->reward( RewardType::FREE_SHIPPING, [] );
		$second = $this->reward( RewardType::FREE_SHIPPING, [] );
		WC()->cart->add_to_cart( $this->product( 20 )->get_id(), 1 );
		WC()->cart->calculate_totals();

		$this->pick( [ (int) $first->get_id(), (int) $second->get_id() ] );

		$this->assertContains( $first->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertNotContains( $second->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertSame( 0.0, (float) WC()->cart->get_shipping_total() );
	}
}
