<?php
namespace YayTests\Integration\Engine\Redemption;

use WP_REST_Request;
use WP_UnitTestCase;
use YayLoyalty\Engine\Redemption\SessionIntent;

/**
 * Removing every coupon through the Store API also cancels the points redemption; removing one merchant coupon doesn't.
 *
 * @group B-2
 */
final class StoreApiRemoveAllCouponsTest extends WP_UnitTestCase {

	private const MERCHANT_CODE = 'b2-merchant10';

	/** @var SessionIntent */
	private $intent;

	public function set_up() {
		parent::set_up();
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		// The Store API loads the cart once per process, so each test starts its own.
		wc_load_cart();

		$product = new \WC_Product_Simple();
		$product->set_regular_price( '100' );
		$product->save();

		$coupon = new \WC_Coupon();
		$coupon->set_code( self::MERCHANT_CODE );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();

		$this->dispatch( 'POST', '/wc/store/v1/cart/add-item', [ 'id' => $product->get_id(), 'quantity' => 1 ] );
		$this->dispatch( 'POST', '/wc/store/v1/cart/apply-coupon', [ 'code' => self::MERCHANT_CODE ] );

		$this->intent = new SessionIntent();
		$this->intent->set( 100 );
	}

	public function tear_down() {
		remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		// The Store API loaded a cart and session; later tests expect neither.
		WC()->cart    = null;
		WC()->session = null;
		parent::tear_down();
	}

	public function test_removing_all_coupons_cancels_the_redemption() {
		$response = $this->dispatch( 'DELETE', '/wc/store/v1/cart/coupons' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], WC()->cart->get_applied_coupons() );
		$this->assertSame( 0, $this->intent->get() );
	}

	public function test_a_refused_remove_all_keeps_the_redemption() {
		remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		$response = $this->dispatch( 'DELETE', '/wc/store/v1/cart/coupons' );

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 100, $this->intent->get() );
	}

	public function test_removing_one_merchant_coupon_keeps_the_redemption() {
		$response = $this->dispatch( 'DELETE', '/wc/store/v1/cart/coupons/' . self::MERCHANT_CODE );

		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( 100, $this->intent->get() );
	}

	public function test_reading_the_cart_keeps_the_redemption() {
		$this->dispatch( 'GET', '/wc/store/v1/cart' );

		$this->assertSame( 100, $this->intent->get() );
	}

	private function dispatch( string $method, string $route, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( [] !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
