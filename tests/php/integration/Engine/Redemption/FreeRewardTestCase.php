<?php
namespace YayTests\Integration\Engine\Redemption;

use WP_UnitTestCase;
use YayLoyalty\Engine\Redemption\CommitWriter;
use YayLoyalty\Engine\Redemption\RewardCartLines;
use YayLoyalty\Engine\Redemption\RewardShipping;
use YayLoyalty\Engine\Redemption\Quoter;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;

/**
 * A signed-in customer with points, a cart, and Pro's free_shipping / free_product rewards as stored rows. Writes roll
 * back with the test suite's transaction.
 */
abstract class FreeRewardTestCase extends WP_UnitTestCase {

	/** @var int */
	protected $user_id;

	/** @var SessionIntent */
	protected $intent;

	public function set_up() {
		parent::set_up();
		// Created by an admin, so the built-in signup campaign (self-registration only) awards nothing.
		wp_set_current_user( 1 );
		$this->user_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->ledger()->adjust( $this->user_id, 1000, 'test', 1 );
		wp_set_current_user( $this->user_id );
		wc_load_cart();
		WC()->cart->empty_cart();
		$this->intent = new SessionIntent();
		$this->intent->clear();
	}

	public function tear_down() {
		WC()->cart    = null;
		WC()->session = null;
		parent::tear_down();
	}

	protected function ledger(): LedgerService {
		return new LedgerService( new LedgerRepository(), new CustomerRepository(), new CampaignRepository() );
	}

	protected function balance(): int {
		return ( new CustomerRepository() )->find_by_user_id( $this->user_id )->get_balance();
	}

	/** A live Pro reward of $type costing 100 points, as a stored row. */
	protected function reward( string $type, array $config ): Reward {
		$now     = Dates::now();
		$rewards = new RewardRepository();
		$id      = $rewards->insert( new Reward( null, 'Free ' . $type, 'p10-' . $type . '-' . wp_generate_password( 6, false ), $type, Status::ACTIVE, 0, 100, array_merge( [ 'currency' => get_woocommerce_currency() ], $config ), [], null, null, false, null, null, $now, $now ) );

		return $rewards->find_reward( $id );
	}

	protected function product( float $price, array $props = [] ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'P10 thing' );
		$product->set_regular_price( (string) $price );
		$product->set_props( $props );
		$product->save();

		return $product;
	}

	/** Picks the rewards on the card and runs the totals, as the redeem endpoints do. */
	protected function pick( array $reward_ids ): void {
		$this->intent->set_rewards( $reward_ids );
		WC()->cart->calculate_totals();
	}

	protected function commit_writer(): CommitWriter {
		$customers = new CustomerRepository();

		return new CommitWriter( $this->ledger(), new RedemptionRepository(), $customers, new ContextFactory( $customers ), new Quoter( new RewardEngine( Registry::with_lite_handlers() ), [] ) );
	}

	/**
	 * The order checkout leaves: the free line at 0 with its meta, a shipping line freed from 7, and both 0 coupon
	 * lines; with $paid, a paid line of two of it first.
	 */
	protected function order( \WC_Product $product, Reward $item, Reward $shipping, ?\WC_Product $paid = null ): \WC_Order {
		$order = wc_create_order( [ 'customer_id' => $this->user_id ] );

		if ( null !== $paid ) {
			$order->add_product( $paid, 2 );
		}

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

	protected function add_marker( \WC_Order $order, Reward $reward ): void {
		$coupon = new \WC_Order_Item_Coupon();
		$coupon->set_code( $reward->get_coupon_code() );
		$coupon->set_discount( 0 );
		$order->add_item( $coupon );
	}
}
