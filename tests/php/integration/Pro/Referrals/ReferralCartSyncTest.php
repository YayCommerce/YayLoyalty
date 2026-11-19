<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
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
		$this->code = ReferralCoupon::code_for( $id );

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
}
