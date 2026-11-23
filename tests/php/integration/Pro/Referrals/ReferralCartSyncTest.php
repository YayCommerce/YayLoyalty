<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
use YayLoyalty\Pro\Referrals\ReferralAttribution;
use YayLoyalty\Pro\Referrals\ReferralCoupon;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * The friend's discount stays off only when the friend removed it, not when an individual-use coupon displaced it.
 *
 * @group P-6
 */
final class ReferralCartSyncTest extends WP_UnitTestCase {

	/** @var string */
	private $code;

	/** @var string */
	private $solo;

	/** @var int */
	private $referral_id;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );
		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB

		$friend     = self::factory()->user->create( [ 'role' => 'customer' ] );
		$id         = ( new ReferralRepository() )->insert_pending(
			self::factory()->user->create( [ 'role' => 'customer' ] ),
			$friend,
			'TESTREF1',
			[
				'discount_type'   => 'fixed',
				'discount_amount' => '10',
				'min_order'       => '0',
			],
			Dates::offset( 30 * DAY_IN_SECONDS )
		);
		$this->code        = ReferralCoupon::code_for( $id );
		$this->referral_id = $id;

		wp_set_current_user( $friend );
		wc_load_cart();
		WC()->session->set( 'yayloyalty_referral_declined', null );
		WC()->cart->empty_cart();

		$product = new \WC_Product_Simple();
		$product->set_regular_price( '60' );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id() );
		WC()->cart->calculate_totals();

		$solo = new \WC_Coupon();
		$solo->set_code( 'solo-' . wp_rand() );
		$solo->set_amount( 2 );
		$solo->set_individual_use( true );
		$solo->save();
		$this->solo = $solo->get_code();
	}

	public function test_an_individual_use_coupon_displaces_the_discount_until_it_is_removed() {
		$this->assertContains( $this->code, WC()->cart->get_applied_coupons() );

		WC()->cart->apply_coupon( $this->solo );
		WC()->cart->calculate_totals();

		$this->assertNotContains( $this->code, WC()->cart->get_applied_coupons() );

		WC()->cart->remove_coupon( $this->solo );
		WC()->cart->calculate_totals();

		$this->assertContains( $this->code, WC()->cart->get_applied_coupons() );
	}

	public function test_the_friend_removing_it_keeps_it_off() {
		WC()->cart->remove_coupon( $this->code );
		WC()->cart->calculate_totals();

		$this->assertNotContains( $this->code, WC()->cart->get_applied_coupons() );
	}

	/**
	 * @group B-2
	 */
	public function test_removing_all_coupons_through_the_store_api_keeps_it_off() {
		$this->assertSame( 200, $this->remove_all_coupons()->get_status() );
		$this->assertNotContains( $this->code, WC()->cart->get_applied_coupons() );
	}

	/**
	 * @group B-2
	 */
	public function test_removing_all_coupons_brings_back_a_discount_an_individual_use_coupon_displaced() {
		WC()->cart->apply_coupon( $this->solo );
		WC()->cart->calculate_totals();
		$this->assertNotContains( $this->code, WC()->cart->get_applied_coupons() );

		$this->assertSame( 200, $this->remove_all_coupons()->get_status() );
		$this->assertContains( $this->code, WC()->cart->get_applied_coupons() );
	}

	private function remove_all_coupons(): \WP_REST_Response {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'DELETE', '/wc/store/v1/cart/coupons' ) );
		remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		WC()->cart->calculate_totals();

		return $response;
	}

	public function test_block_checkout_gives_a_friend_who_signed_up_in_it_the_discount_before_payment() {
		do_action( ReferralAttribution::CREATED_ACTION, $this->referral_id, get_current_user_id() );
		$order = $this->signup_order();

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assertContains( $this->code, $order->get_coupon_codes() );
		$this->assertEquals( 50, (float) wc_get_order( $order->get_id() )->get_total() );
	}

	public function test_block_checkout_leaves_a_returning_friends_order_alone() {
		$order = $this->signup_order();

		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assertNotContains( $this->code, $order->get_coupon_codes() );
	}

	private function signup_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( '60' );
		$product->save();

		$order = wc_create_order( [ 'customer_id' => get_current_user_id() ] );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}
}
