<?php
namespace YayTests\Integration\Engine\Redemption;

use YayLoyalty\Enums\RewardType;

require_once __DIR__ . '/FreeRewardTestCase.php';

/**
 * A refund gives back a free reward's points only for the gift it refunds: a free product for its refunded units,
 * free shipping once the whole order is refunded. Refunding paid items gives back neither.
 *
 * @group P-11
 */
final class FreeRewardRefundTest extends FreeRewardTestCase {

	public function test_a_partial_refund_gives_back_free_reward_points_only_for_the_refunded_gift() {
		$paid     = $this->product( 10 );
		$gift     = $this->product( 12.5 );
		$item     = $this->reward( RewardType::FREE_PRODUCT, [ 'product_id' => $gift->get_id(), 'qty' => 2 ] );
		$shipping = $this->reward( RewardType::FREE_SHIPPING, [] );
		$order    = $this->order( $gift, $item, $shipping, $paid );
		$this->commit_writer()->commit_rewards(
			$order,
			[
				$item->get_coupon_code()     => $item,
				$shipping->get_coupon_code() => $shipping,
			]
		);
		$this->assertSame( 800, $this->balance() );

		$this->refund( $order, $this->line_of( $order, $paid ), 10.0 );
		$this->assertSame( 800, $this->balance() );

		$this->refund( $order, $this->line_of( $order, $gift ), 0.0 );
		$this->assertSame( 850, $this->balance() );

		$this->refund( $order, $this->line_of( $order, $paid ), 10.0 );
		$this->assertSame( 1000, $this->balance() );
	}

	/** Refunds one unit of $item_id for $amount, as wp-admin's refund form does. */
	private function refund( \WC_Order $order, int $item_id, float $amount ): void {
		$refund = wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'line_items' => [
					$item_id => [
						'qty'          => 1,
						'refund_total' => $amount,
					],
				],
			]
		);
		$this->assertNotWPError( $refund );
	}

	private function line_of( \WC_Order $order, \WC_Product $product ): int {
		foreach ( wc_get_order( $order->get_id() )->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $product->get_id() === $item->get_product_id() ) {
				return (int) $item_id;
			}
		}

		$this->fail( 'No line for the product.' );
	}
}
