<?php
namespace YayTests\Integration\Pro\Services;

use YayLoyalty\Engine\Redemption\RewardCoupons;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Pro\Services\FreeProductRewardHandler;
use YayLoyalty\Repositories\CustomerRepository;
use YayTests\Integration\Engine\Redemption\FreeRewardTestCase;


require_once dirname( __DIR__, 2 ) . '/Engine/Redemption/FreeRewardTestCase.php';

/**
 * The free-product reward is worth the product's price × qty, and can't be used when the product can't be added,
 * or would go past its stock or a one-per-order limit counting the shopper's own copies.
 *
 * @group P-10
 */
final class FreeProductRewardHandlerTest extends FreeRewardTestCase {

	public function test_free_product_quote_denies_no_value_for_a_missing_or_out_of_stock_product() {
		$in_stock = $this->product( 8 );
		$sold_out = $this->product(
			8,
			[
				'manage_stock'   => true,
				'stock_quantity' => 1,
			]
		);

		$this->assertSame( 16.0, $this->quote( $in_stock->get_id(), 2 )->get_value() );
		$this->assertSame( RewardCoupons::REASON_OUT_OF_STOCK, $this->quote( $sold_out->get_id(), 2 )->get_reason() );
		$this->assertSame( RewardCoupons::REASON_NO_VALUE, $this->quote( 999999, 1 )->get_reason() );
	}

	/** @group P-11 */
	public function test_free_product_is_denied_past_stock_counting_the_shoppers_copies() {
		$product = $this->product(
			8,
			[
				'manage_stock'   => true,
				'stock_quantity' => 1,
			]
		);

		$this->assertTrue( $this->quote( $product->get_id(), 1 )->is_allowed() );

		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->assertSame( RewardCoupons::REASON_OUT_OF_STOCK, $this->quote( $product->get_id(), 1 )->get_reason() );
	}

	/** @group P-11 */
	public function test_free_product_is_denied_for_a_sold_individually_product_in_the_cart() {
		$product = $this->product( 8, [ 'sold_individually' => true ] );

		$this->assertTrue( $this->quote( $product->get_id(), 1 )->is_allowed() );

		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->assertSame( RewardCoupons::REASON_SOLD_INDIVIDUALLY, $this->quote( $product->get_id(), 1 )->get_reason() );
	}

	private function quote( int $product_id, int $qty ): \YayLoyalty\Engine\Rewards\Result {
		$reward = $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $product_id, 'qty' => $qty ] );
		$ctx    = ( new ContextFactory( new CustomerRepository() ) )->from_cart( WC()->cart, $this->user_id, null );

		return ( new FreeProductRewardHandler() )->quote( $reward, $ctx );
	}
}
