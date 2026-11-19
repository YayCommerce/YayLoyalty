<?php
namespace YayTests\Integration\Pro\Referrals;

use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Referrals\ReferralLifecycle;
use YayLoyalty\Pro\Referrals\ReferralPayout;
use YayLoyalty\Pro\Referrals\ReferralRunner;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

require_once __DIR__ . '/ReferralTestCase.php';

/**
 * A first order that completed while the program was off is decided when the program is switched back on.
 *
 * @group P-6
 */
final class ReferralRecheckTest extends ReferralTestCase {

	public function set_up() {
		parent::set_up();
		as_unschedule_all_actions( ReferralRunner::RUN_ACTION );
		update_option( ReferralSettings::OPTION, [ 'enabled' => false ] );
	}

	private function runner(): ReferralRunner {
		$payout = new ReferralPayout( new ReferralRepository() );

		return new ReferralRunner( new ReferralRepository(), $payout, new ReferralLifecycle( new ReferralRepository(), $payout ) );
	}

	public function test_switching_the_program_on_holds_a_first_order_that_completed_while_it_was_off() {
		$order_id = $this->complete_order( 60.0, 'friend@example.test' );
		$this->assertSame( ReferralStatus::PENDING, $this->referral()->get_status() );

		ReferralSettings::update( [ 'enabled' => true ] );

		$this->assertTrue( as_has_scheduled_action( ReferralRunner::RUN_ACTION, [ 'recheck', 0 ] ) );

		$this->runner()->run( 'recheck', 0 );

		$this->assertSame( ReferralStatus::ON_HOLD, $this->referral()->get_status() );
		$this->assertSame( $order_id, $this->referral()->get_first_order_id() );
	}

	public function test_the_recheck_rejects_a_first_order_under_the_minimum() {
		$this->complete_order( 40.0, 'friend@example.test' );
		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );

		$this->runner()->run( 'recheck', 0 );

		$this->assertSame( ReferralStatus::REJECTED, $this->referral()->get_status() );
		$this->assertSame( ReferralRejectReason::NOT_QUALIFYING, $this->referral()->to_array()['reject_reason'] );
	}

	public function test_a_lapsed_window_does_not_expire_a_referral_whose_first_order_completed_while_off() {
		global $wpdb;
		$this->complete_order( 60.0, 'friend@example.test' );
		$wpdb->update( Table::name( 'referrals' ), [ 'coupon_expires_at' => Dates::offset( -60 ) ], [ 'id' => $this->referral_id ] ); // phpcs:ignore WordPress.DB

		$this->runner()->run( 'expire', 0 );

		$this->assertSame( ReferralStatus::PENDING, $this->referral()->get_status() );

		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );
		$this->runner()->run( 'recheck', 0 );

		$this->assertSame( ReferralStatus::ON_HOLD, $this->referral()->get_status() );
	}

	public function test_saving_other_settings_while_on_queues_nothing() {
		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );
		as_unschedule_all_actions( ReferralRunner::RUN_ACTION );

		ReferralSettings::update( [ 'hold_days' => 7 ] );

		$this->assertFalse( as_has_scheduled_action( ReferralRunner::RUN_ACTION, [ 'recheck', 0 ] ) );
	}
}
