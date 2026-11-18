<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Referrals\ReferralPayout;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
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

	public function test_a_zero_day_hold_pays_at_once_without_touching_the_friends_order_points() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB
		$this->referral_id = $this->refer( 0 );
		$before            = $this->balance( $this->advocate_id );

		$order_id = $this->complete_order( 60.0, 'friend@example.test' );

		$this->assertSame( ReferralStatus::REWARDED, $this->referral()->get_status() );
		$this->assertSame( $before + 1000, $this->balance( $this->advocate_id ) );
		$this->assertNotSame( '1000', (string) wc_get_order( $order_id )->get_meta( PendingPoints::META_EARNED ) );

		$rows = ( new LedgerRepository() )->earn_rows_for_order( $order_id );
		$ours = array_values( array_filter( $rows, static fn( $row ): bool => LedgerSource::REFERRAL === $row->get_source() ) );
		$this->assertCount( 1, $ours );
		$this->assertSame( $this->advocate_id, $ours[0]->get_user_id() );
	}

	public function test_paying_twice_pays_once() {
		$this->complete_order( 60.0, 'friend@example.test' );
		$payout = new ReferralPayout( new ReferralRepository() );
		$before = $this->balance( $this->advocate_id );

		$this->assertSame( ReferralStatus::REWARDED, $payout->pay( $this->referral() ) );
		$this->assertSame( ReferralStatus::REWARDED, $payout->pay( $this->referral() ) );
		$this->assertSame( $before + 1000, $this->balance( $this->advocate_id ) );
	}

	public function test_a_referral_rejected_after_it_was_loaded_is_not_paid() {
		$this->complete_order( 60.0, 'friend@example.test' );
		$stale  = $this->referral();
		$before = $this->balance( $this->advocate_id );
		( new ReferralRepository() )->reject( $this->referral_id, ReferralRejectReason::REFUNDED_DURING_HOLD, ReferralStatus::ON_HOLD );

		$this->assertSame( ReferralStatus::REJECTED, ( new ReferralPayout( new ReferralRepository() ) )->pay( $stale ) );
		$this->assertSame( $before, $this->balance( $this->advocate_id ) );
	}

	public function test_a_blocking_gate_leaves_the_referral_on_hold() {
		$this->complete_order( 60.0, 'friend@example.test' );
		update_user_meta( $this->advocate_id, ProgramGate::META_OPTED_OUT, 1 );

		$this->assertSame( ReferralStatus::ON_HOLD, ( new ReferralPayout( new ReferralRepository() ) )->pay( $this->referral() ) );
		$this->assertSame( ReferralStatus::ON_HOLD, $this->referral()->get_status() );
	}

	protected function balance( int $user_id ): int {
		$customer = ( new CustomerRepository() )->find_by_user_id( $user_id );

		return null === $customer ? 0 : $customer->get_balance();
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
