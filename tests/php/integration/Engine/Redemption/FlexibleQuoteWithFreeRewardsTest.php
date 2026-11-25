<?php
namespace YayTests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Enums\RewardType;

require_once __DIR__ . '/FreeRewardTestCase.php';

/**
 * A picked free reward pays its cost from the balance but leaves the items the points discount can come off as they
 * were: the free line is already 0 in the cart, and freed shipping was never part of it.
 *
 * @group B-3
 */
final class FlexibleQuoteWithFreeRewardsTest extends FreeRewardTestCase {

	/** @var int */
	private $instance_id = 0;

	public function set_up() {
		parent::set_up();
		// Enough points that the balance never caps the quote, so only the cart decides it.
		$this->ledger()->adjust( $this->user_id, 1000000, 'test', 1 );
	}

	public function tear_down() {
		if ( $this->instance_id ) {
			\WC_Shipping_Zones::get_zone( 0 )->delete_shipping_method( $this->instance_id );
		}

		parent::tear_down();
	}

	public function test_a_free_product_reward_leaves_the_points_discount_base_unchanged() {
		$reward = $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $this->product( 18 )->get_id(), 'qty' => 1 ] );
		WC()->cart->add_to_cart( $this->product( 20 )->get_id(), 1 );
		WC()->cart->calculate_totals();
		$before = $this->ceiling_points();

		$this->pick( [ (int) $reward->get_id() ] );

		$this->assertContains( $reward->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertGreaterThan( 0, $before );
		$this->assertSame( $before, $this->ceiling_points() );
	}

	public function test_a_free_shipping_reward_leaves_the_points_discount_base_unchanged() {
		$this->instance_id = (int) \WC_Shipping_Zones::get_zone( 0 )->add_shipping_method( 'flat_rate' );
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

		$reward = $this->reward( RewardType::FREE_SHIPPING, [] );
		WC()->cart->add_to_cart( $this->product( 20 )->get_id(), 1 );
		WC()->cart->calculate_totals();
		$before = $this->ceiling_points();

		$this->pick( [ (int) $reward->get_id() ] );

		$this->assertSame( 0.0, (float) WC()->cart->get_shipping_total() );
		$this->assertSame( $before, $this->ceiling_points() );
	}

	/** The most points the flexible discount takes on this cart now. */
	private function ceiling_points(): int {
		return RedemptionService::build( $this->intent )->ceiling( WC()->cart, $this->user_id )->get_points();
	}
}
