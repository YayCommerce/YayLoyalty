<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * The friend's first completed order starts the hold, and an order that doesn't qualify rejects the referral.
 *
 * @group P-4
 */
final class ReferralPayoutTest extends WP_UnitTestCase {

	/** @var int */
	protected $advocate_id;

	/** @var int */
	protected $friend_id;

	/** @var int */
	protected $referral_id;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );
		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB

		$this->advocate_id = self::factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'advocate@example.test',
			]
		);
		$this->friend_id   = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->referral_id = $this->refer( 14 );
	}

	public function test_a_qualifying_first_order_holds_until_the_hold_ends() {
		$order_id = $this->complete_order( 60.0, 'friend@example.test' );
		$referral = $this->referral();

		$this->assertSame( ReferralStatus::ON_HOLD, $referral->get_status() );
		$this->assertSame( $order_id, $referral->get_first_order_id() );
		$this->assertEqualsWithDelta( time() + 14 * DAY_IN_SECONDS, strtotime( $referral->to_array()['hold_until'] . ' UTC' ), 60 );
	}

	public function test_an_order_under_the_minimum_rejects_the_referral() {
		$this->complete_order( 40.0, 'friend@example.test' );

		$this->assertSame( ReferralStatus::REJECTED, $this->referral()->get_status() );
		$this->assertSame( ReferralRejectReason::NOT_QUALIFYING, $this->referral()->to_array()['reject_reason'] );
	}

	public function test_the_advocates_billing_email_is_a_self_referral() {
		$this->complete_order( 60.0, 'advocate@example.test' );

		$this->assertSame( ReferralRejectReason::SELF_REFERRAL, $this->referral()->to_array()['reject_reason'] );
	}

	public function test_a_completion_with_the_program_off_leaves_the_referral_pending() {
		update_option( ReferralSettings::OPTION, [ 'enabled' => false ] );

		$this->complete_order( 60.0, 'friend@example.test' );

		$this->assertSame( ReferralStatus::PENDING, $this->referral()->get_status() );
	}

	protected function refer( int $hold_days ): int {
		return ( new ReferralRepository() )->insert_pending(
			$this->advocate_id,
			$this->friend_id,
			'TESTREF1',
			[
				'discount_type'         => 'fixed',
				'discount_amount'       => '10',
				'min_order'             => '50',
				'advocate_points'       => 1000,
				'hold_days'             => $hold_days,
				'exclude_other_coupons' => true,
			],
			Dates::offset( 30 * DAY_IN_SECONDS )
		);
	}

	protected function complete_order( float $price, string $billing_email ): int {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->save();

		$order = wc_create_order( [ 'customer_id' => $this->friend_id ] );
		$order->add_product( $product, 1 );
		$order->set_billing_email( $billing_email );
		$order->calculate_totals();
		$order->set_date_created( time() - MINUTE_IN_SECONDS );
		$order->save();
		$order->update_status( 'completed' );

		return (int) $order->get_id();
	}

	protected function referral(): Referral {
		$referral = ( new ReferralRepository() )->find( $this->referral_id );
		$this->assertInstanceOf( Referral::class, $referral );

		return $referral;
	}
}
