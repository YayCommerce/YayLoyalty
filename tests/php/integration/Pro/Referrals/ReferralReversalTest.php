<?php
namespace YayTests\Integration\Pro\Referrals;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Referrals\ReferralPayout;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Repositories\LedgerRepository;

require_once __DIR__ . '/ReferralTestCase.php';

/**
 * A refund during the hold rejects the referral and moves no points; a full refund after payout claws them back and reverses it.
 *
 * @group P-4
 */
final class ReferralReversalTest extends ReferralTestCase {

	public function test_a_refund_during_the_hold_rejects_and_writes_no_referral_row() {
		$order_id = $this->complete_order( 60.0, 'friend@example.test' );

		wc_create_refund(
			[
				'order_id' => $order_id,
				'amount'   => 10,
			]
		);

		$this->assertSame( ReferralStatus::REJECTED, $this->referral()->get_status() );
		$this->assertSame( ReferralRejectReason::REFUNDED_DURING_HOLD, $this->referral()->to_array()['reject_reason'] );
		$this->assertSame( [], $this->referral_rows( $order_id ) );
	}

	public function test_a_cancel_during_the_hold_rejects() {
		$order_id = $this->complete_order( 60.0, 'friend@example.test' );

		wc_get_order( $order_id )->update_status( 'cancelled' );

		$this->assertSame( ReferralRejectReason::REFUNDED_DURING_HOLD, $this->referral()->to_array()['reject_reason'] );
	}

	public function test_a_full_refund_after_payout_claws_back_and_reverses() {
		$order_id = $this->complete_order( 60.0, 'friend@example.test' );
		$before   = $this->balance( $this->advocate_id );
		( new ReferralPayout( new ReferralRepository() ) )->pay( $this->referral() );

		$order = wc_get_order( $order_id );
		wc_create_refund(
			[
				'order_id'   => $order_id,
				'amount'     => $order->get_total(),
				'line_items' => [],
			]
		);

		$this->assertSame( ReferralStatus::REVERSED, $this->referral()->get_status() );
		$this->assertSame( $before, $this->balance( $this->advocate_id ) );
	}

	/** @return array<int, mixed> The order's referral rows. */
	private function referral_rows( int $order_id ): array {
		return array_values( array_filter( ( new LedgerRepository() )->earn_rows_for_order( $order_id ), static fn( $row ): bool => LedgerSource::REFERRAL === $row->get_source() ) );
	}
}
