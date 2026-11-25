<?php
namespace YayTests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Repositories\RedemptionRepository;


require_once __DIR__ . '/FreeRewardTestCase.php';

/**
 * At checkout a free reward debits its full cost and its row keeps what it freed: the product's price × qty, or the
 * shipping it took off; cancelling the order gives the points back.
 *
 * @group P-10
 */
final class FreeRewardCommitTest extends FreeRewardTestCase {

	public function test_a_free_reward_commit_stores_the_freed_value_and_a_cancel_restores_the_points() {
		$product  = $this->product( 12.5 );
		$item     = $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $product->get_id(), 'qty' => 2 ] );
		$shipping = $this->reward( RewardType::FREE_SHIPPING, [] );
		$order    = $this->order( $product, $item, $shipping );

		$outcome = $this->commit_writer()->commit_rewards(
			$order,
			[
				$item->get_coupon_code()     => $item,
				$shipping->get_coupon_code() => $shipping,
			]
		);

		$this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
		$this->assertSame( 800, $this->balance() );
		$rows = new RedemptionRepository();
		$this->assertSame( 25.0, (float) $rows->find_by_order( $order->get_id(), (int) $item->get_id() )->get_value() );
		$this->assertSame( 7.0, (float) $rows->find_by_order( $order->get_id(), (int) $shipping->get_id() )->get_value() );

		wc_get_order( $order->get_id() )->update_status( 'cancelled' );

		$this->assertSame( 1000, $this->balance() );
	}

	/** @group P-11 */
	public function test_the_order_screen_hides_every_meta_a_free_reward_leaves() {
		$product = $this->product( 12.5 );
		$order   = $this->order( $product, $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $product->get_id(), 'qty' => 2 ] ), $this->reward( RewardType::FREE_SHIPPING, [] ) );
		$hidden  = apply_filters( 'woocommerce_hidden_order_itemmeta', [] );

		foreach ( $order->get_items( [ 'line_item', 'shipping' ] ) as $item ) {
			foreach ( $item->get_meta_data() as $meta ) {
				$this->assertContains( $meta->key, $hidden );
			}
		}
	}

	public function test_a_free_product_order_without_its_line_debits_nothing() {
		$product = $this->product( 12.5 );
		$item    = $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $product->get_id(), 'qty' => 1 ] );
		$order   = wc_create_order( [ 'customer_id' => $this->user_id ] );
		$this->add_marker( $order, $item );
		$order->save();

		$outcome = $this->commit_writer()->commit_rewards( $order, [ $item->get_coupon_code() => $item ] );

		$this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
		$this->assertSame( 1000, $this->balance() );
	}
}
