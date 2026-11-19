<?php
namespace YayTests\Integration\Pro\Referrals;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Pro\Controllers\ReferralsRestController;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Referrals\ReferralLifecycle;
use YayLoyalty\Pro\Referrals\ReferralPayout;
use YayLoyalty\Pro\Referrals\ReferralRunner;
use YayLoyalty\Pro\Repositories\ReferralListRepository;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

require_once __DIR__ . '/ReferralTestCase.php';

/**
 * A held referral whose advocate can't be paid is rejected: at once when the account is gone, after the grace period when they opted out.
 *
 * @group P-6
 */
final class ReferralUnpayableTest extends ReferralTestCase {

	private function run_with_hold_ended( int $days_ago ): void {
		global $wpdb;
		$wpdb->update( Table::name( 'referrals' ), [ 'hold_until' => Dates::offset( -$days_ago * DAY_IN_SECONDS - 60 ) ], [ 'id' => $this->referral_id ] ); // phpcs:ignore WordPress.DB
		$payout = new ReferralPayout( new ReferralRepository() );

		( new ReferralRunner( new ReferralRepository(), $payout, new ReferralLifecycle( new ReferralRepository(), $payout ) ) )->run();
	}

	public function test_an_opted_out_advocate_past_the_grace_period_is_rejected() {
		$this->complete_order( 60.0, 'friend@example.test' );
		update_user_meta( $this->advocate_id, ProgramGate::META_OPTED_OUT, 1 );

		$this->run_with_hold_ended( ReferralPayout::GRACE_DAYS + 1 );

		$this->assertSame( ReferralStatus::REJECTED, $this->referral()->get_status() );
		$this->assertSame( ReferralRejectReason::ADVOCATE_CANNOT_EARN, $this->referral()->to_array()['reject_reason'] );
	}

	public function test_an_opted_out_advocate_inside_the_grace_period_stays_on_hold_with_the_date_shown() {
		$this->complete_order( 60.0, 'friend@example.test' );
		update_user_meta( $this->advocate_id, ProgramGate::META_OPTED_OUT, 1 );

		$this->run_with_hold_ended( 1 );

		$this->assertSame( ReferralStatus::ON_HOLD, $this->referral()->get_status() );

		$row = ( new ReferralListRepository() )->paginate( [ 'status' => ReferralStatus::ON_HOLD ] )['items'][0];
		$this->assertNotNull( ReferralsRestController::with_order( $row )['rejects_at'] );
	}

	public function test_a_deleted_advocate_is_rejected_when_the_hold_ends() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$this->complete_order( 60.0, 'friend@example.test' );
		wp_delete_user( $this->advocate_id );

		$this->run_with_hold_ended( 0 );

		$this->assertSame( ReferralRejectReason::ADVOCATE_CANNOT_EARN, $this->referral()->to_array()['reject_reason'] );
	}

	public function test_an_advocate_who_can_earn_has_no_rejection_date() {
		$this->complete_order( 60.0, 'friend@example.test' );

		$row = ( new ReferralListRepository() )->paginate( [ 'status' => ReferralStatus::ON_HOLD ] )['items'][0];

		$this->assertNull( ReferralsRestController::with_order( $row )['rejects_at'] );
	}
}
