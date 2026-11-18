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
use YayLoyalty\Pro\Referrals\ReferralRunner;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

require_once __DIR__ . '/ReferralTestCase.php';

/**
 * The friend's first completed order starts the hold, and an order that doesn't qualify rejects the referral.
 *
 * @group P-4
 */
final class ReferralPayoutTest extends ReferralTestCase {

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

	public function test_the_job_pays_a_released_hold_once_and_expires_a_lapsed_pending_referral() {
		global $wpdb;
		$this->complete_order( 60.0, 'friend@example.test' );
		$wpdb->update( Table::name( 'referrals' ), [ 'hold_until' => Dates::offset( -60 ) ], [ 'id' => $this->referral_id ] ); // phpcs:ignore WordPress.DB

		$lapsed_friend = self::factory()->user->create( [ 'role' => 'customer' ] );
		$lapsed        = ( new ReferralRepository() )->insert_pending( $this->advocate_id, $lapsed_friend, 'TESTREF1', [ 'advocate_points' => 1000 ], Dates::offset( -60 ) );
		$before        = $this->balance( $this->advocate_id );
		$runner        = new ReferralRunner( new ReferralRepository(), new ReferralPayout( new ReferralRepository() ) );

		$runner->run();
		$runner->run();

		$this->assertSame( ReferralStatus::REWARDED, $this->referral()->get_status() );
		$this->assertSame( $before + 1000, $this->balance( $this->advocate_id ) );
		$this->assertSame( ReferralStatus::EXPIRED, ( new ReferralRepository() )->find( $lapsed )->get_status() );
	}

	public function test_a_continuation_through_the_hook_resumes_after_its_cursor() {
		$lapsed_friend = self::factory()->user->create( [ 'role' => 'customer' ] );
		$lapsed        = ( new ReferralRepository() )->insert_pending( $this->advocate_id, $lapsed_friend, 'TESTREF1', [ 'advocate_points' => 1000 ], Dates::offset( -60 ) );

		do_action( ReferralRunner::RUN_ACTION, 'expire', $lapsed );

		$this->assertSame( ReferralStatus::PENDING, ( new ReferralRepository() )->find( $lapsed )->get_status() );

		do_action( ReferralRunner::RUN_ACTION, 'expire', $lapsed - 1 );

		$this->assertSame( ReferralStatus::EXPIRED, ( new ReferralRepository() )->find( $lapsed )->get_status() );
	}
}
