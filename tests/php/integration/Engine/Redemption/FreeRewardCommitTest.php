<?php
namespace YayTests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\RewardCartLines;
use YayLoyalty\Engine\Redemption\RewardShipping;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;
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

	/** The order checkout leaves: the free line at 0 with its meta, a shipping line freed from 7, and both 0 coupon lines. */
	private function order( \WC_Product $product, Reward $item, Reward $shipping ): \WC_Order {
		$order = wc_create_order( [ 'customer_id' => $this->user_id ] );

		$line = new \WC_Order_Item_Product();
		$line->set_product( $product );
		$line->set_quantity( 2 );
		$line->set_subtotal( 0 );
		$line->set_total( 0 );
		$line->add_meta_data( RewardCartLines::META_REWARD, (int) $item->get_id(), true );
		$line->add_meta_data( RewardCartLines::META_PRICE, '12.5', true );
		$order->add_item( $line );

		$rate = new \WC_Order_Item_Shipping();
		$rate->set_method_title( 'Flat rate' );
		$rate->set_total( 0 );
		$rate->add_meta_data( RewardShipping::META_COST, '7', true );
		$rate->add_meta_data( RewardShipping::META_TAXES, [], true );
		$rate->add_meta_data( RewardShipping::META_REWARD, (int) $shipping->get_id(), true );
		$order->add_item( $rate );

		$this->add_marker( $order, $item );
		$this->add_marker( $order, $shipping );
		$order->calculate_totals( false );
		$order->save();

		return $order;
	}

	private function add_marker( \WC_Order $order, Reward $reward ): void {
		$coupon = new \WC_Order_Item_Coupon();
		$coupon->set_code( $reward->get_coupon_code() );
		$coupon->set_discount( 0 );
		$order->add_item( $coupon );
	}
}
