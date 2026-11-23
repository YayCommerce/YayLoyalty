<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
use YayLoyalty\Pro\Referrals\ReferralCoupon;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * The friend's referral code is theirs alone, and only while the referral is pending, unexpired and live, before a paid order.
 *
 * @group P-4
 */
final class ReferralCouponTest extends WP_UnitTestCase {

	/** @var int */
	private $friend_id;

	/** @var int */
	private $referral_id;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );
		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB

		$advocate_id       = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->friend_id   = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->referral_id = ( new ReferralRepository() )->insert_pending(
			$advocate_id,
			$this->friend_id,
			'TESTREF1',
			[
				'discount_type'   => 'fixed',
				'discount_amount' => '10',
				'min_order'       => '0',
			],
			Dates::offset( 30 * DAY_IN_SECONDS )
		);

		wp_set_current_user( $this->friend_id );
	}

	public function test_the_code_is_valid_for_the_friend_with_its_terms() {
		$coupon = new \WC_Coupon( ReferralCoupon::code_for( $this->referral_id ) );

		$this->assertTrue( $this->is_valid() );
		$this->assertSame( 'fixed_cart', $coupon->get_discount_type() );
		$this->assertEquals( 10, $coupon->get_amount() );
	}

	public function test_another_user_cannot_use_it() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );

		$this->assertFalse( $this->is_valid() );
	}

	public function test_it_is_invalid_after_the_coupon_expires() {
		global $wpdb;
		$wpdb->update( Table::name( 'referrals' ), [ 'coupon_expires_at' => Dates::offset( -60 ) ], [ 'id' => $this->referral_id ] ); // phpcs:ignore WordPress.DB

		$this->assertFalse( $this->is_valid() );
	}

	public function test_it_is_invalid_after_a_paid_order() {
		$order = wc_create_order( [ 'customer_id' => $this->friend_id ] );
		$order->set_status( 'completed' );
		$order->set_date_created( time() - HOUR_IN_SECONDS );
		$order->save();

		$this->assertFalse( $this->is_valid() );
	}

	public function test_a_first_order_still_processing_stops_a_second_use() {
		$order = wc_create_order( [ 'customer_id' => $this->friend_id ] );
		$order->set_status( 'processing' );
		$order->save();

		$this->assertFalse( $this->is_valid() );
	}

	public function test_it_is_invalid_with_the_program_off() {
		update_option( ReferralSettings::OPTION, [ 'enabled' => false ] );

		$this->assertFalse( $this->is_valid() );
	}

	private function is_valid(): bool {
		$coupon = new \WC_Coupon( ReferralCoupon::code_for( $this->referral_id ) );

		return true === ( new \WC_Discounts() )->is_coupon_valid( $coupon );
	}
}
