<?php
namespace YayTests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Redemption\RewardCartLines;
use YayLoyalty\Enums\RewardType;


require_once __DIR__ . '/FreeRewardTestCase.php';

/**
 * A picked free-product reward puts one locked line at 0 on the cart while its coupon is applied, never touches the
 * shopper's own copy, and leaves with its line.
 *
 * @group P-10
 */
final class RewardCartLinesTest extends FreeRewardTestCase {

	public function test_an_applied_free_product_marker_adds_one_locked_line_at_zero_and_leaves_the_shoppers_copy() {
		$product = $this->product( 10 );
		$reward  = $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $product->get_id(), 'qty' => 2 ] );
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->pick( [ (int) $reward->get_id() ] );
		WC()->cart->calculate_totals();

		$free = $this->free_lines();
		$this->assertContains( $reward->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertCount( 1, $free );
		$this->assertSame( 2, (int) $free[0]['quantity'] );
		$this->assertSame( 0.0, (float) $free[0]['data']->get_price() );
		$this->assertCount( 2, WC()->cart->get_cart() );
		$this->assertSame( 10.0, (float) WC()->cart->get_subtotal() );
		$this->assertSame( '', apply_filters( 'woocommerce_cart_item_remove_link', 'link', $free[0]['key'] ) );

		$this->pick( [] );

		$this->assertSame( [], $this->free_lines() );
		$this->assertNotContains( $reward->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertCount( 1, WC()->cart->get_cart() );
	}

	public function test_a_reward_line_whose_coupon_is_not_applied_is_dropped() {
		$product = $this->product( 10 );
		WC()->cart->add_to_cart( $product->get_id(), 1, 0, [], [ RewardCartLines::ITEM_KEY => 999999 ] );

		WC()->cart->calculate_totals();

		$this->assertSame( [], $this->free_lines() );
	}

	public function test_removing_the_free_line_drops_the_reward_from_the_session() {
		$product = $this->product( 10 );
		$reward  = $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $product->get_id(), 'qty' => 1 ] );
		WC()->cart->add_to_cart( $this->product( 30 )->get_id(), 1 );
		$this->pick( [ (int) $reward->get_id() ] );

		WC()->cart->remove_cart_item( $this->free_lines()[0]['key'] );
		WC()->cart->calculate_totals();

		$this->assertSame( [], $this->intent->rewards() );
		$this->assertNotContains( $reward->get_coupon_code(), WC()->cart->get_applied_coupons() );
		$this->assertSame( [], $this->free_lines() );
	}

	/** @return array<int, array> */
	private function free_lines(): array {
		$lines = [];

		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( ! empty( $line[ RewardCartLines::ITEM_KEY ] ) ) {
				$lines[] = $line + [ 'key' => $key ];
			}
		}

		return $lines;
	}
}
