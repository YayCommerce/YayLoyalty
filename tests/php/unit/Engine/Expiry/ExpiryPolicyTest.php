<?php
namespace YayTests\Unit\Engine\Expiry;

use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;
use YayTests\Unit\TestCase;

/**
 * @group baseline
 */
final class ExpiryPolicyTest extends TestCase {

	private const NOW = '2026-06-01 00:00:00';

	public function test_a_batch_lands_on_the_same_day_or_the_month_end() {
		$this->assertSame( '2025-02-28 10:00:00', ExpiryPolicy::batch_expires_at( '2025-01-31 10:00:00', 1 ) );
		$this->assertSame( '2024-02-29 10:00:00', ExpiryPolicy::batch_expires_at( '2024-01-31 10:00:00', 1 ) );
		$this->assertSame( '2026-02-28 08:00:00', ExpiryPolicy::batch_expires_at( '2025-11-30 08:00:00', 3 ) );
		$this->assertSame( '2025-04-15 00:00:00', ExpiryPolicy::batch_expires_at( '2025-01-15 00:00:00', 3 ) );
	}

	public function test_the_reminder_always_goes_out_inside_the_window() {
		$this->assertSame( 29, $this->policy( [ 'inactivity_days' => 30, 'reminder_days' => 30 ] )->reminder_days() );
		$this->assertSame( 7, $this->policy( [ 'inactivity_days' => 30, 'reminder_days' => 7 ] )->reminder_days() );
	}

	public function test_lite_runs_a_stored_batch_mode_as_inactivity_with_the_oldest_spent_first() {
		$policy = $this->policy( [ 'mode' => 'batch', 'batch_months' => 3, 'inactivity_days' => 90, 'spend_order' => 'newest' ] );

		$this->assertSame( 'inactivity', $policy->mode() );
		$this->assertSame( 90, $policy->window_days() );
		$this->assertSame( ExpiryPolicy::SPEND_OLDEST, $policy->spend_order() );
	}

	public function test_batch_counts_a_month_as_28_days_and_honours_newest_first() {
		$this->given_batch_mode_is_available();
		$policy = $this->policy( [ 'mode' => 'batch', 'batch_months' => 3, 'spend_order' => 'newest' ] );

		$this->assertSame( 84, $policy->window_days() );
		$this->assertSame( ExpiryPolicy::SPEND_NEWEST, $policy->spend_order() );
		$this->assertSame( '1|batch|3', $policy->signature() );
	}

	public function test_activity_resets_the_clock_but_never_moves_a_kept_date_sooner() {
		$policy = $this->policy( [ 'inactivity_days' => 30 ] );
		$earn   = $this->row( 'earn', 10, '2026-05-01 00:00:00' );

		$this->assertSame( '2026-05-31 00:00:00', $policy->customer_expires_at( $earn, $this->customer( 50, null ), null, self::NOW ) );
		$this->assertSame( '2026-09-01 00:00:00', $policy->customer_expires_at( $earn, $this->customer( 50, '2026-09-01 00:00:00' ), null, self::NOW ) );
	}

	public function test_an_adjust_keeps_a_date_still_ahead_and_otherwise_waits_out_the_grace_floor() {
		$policy = $this->policy( [ 'inactivity_days' => 365 ] );
		$adjust = $this->row( 'adjust', 10, self::NOW );

		$this->assertSame( '2026-08-15 00:00:00', $policy->customer_expires_at( $adjust, $this->customer( 50, '2026-08-15 00:00:00' ), null, self::NOW ) );
		$this->assertSame(
			'2026-07-01 00:00:00',
			$policy->customer_expires_at( $adjust, $this->customer( 50, '2026-01-01 00:00:00', '2025-01-01 00:00:00' ), null, self::NOW )
		);
	}

	public function test_no_balance_left_means_no_date_and_expiry_off_keeps_the_stored_one() {
		$spend = $this->row( 'redeem', -50, self::NOW );

		$this->assertNull( $this->policy( [] )->customer_expires_at( $spend, $this->customer( 50, '2026-07-01 00:00:00' ), null, self::NOW ) );
		$this->assertSame(
			'2026-07-01 00:00:00',
			$this->policy( [ 'enabled' => false ] )->customer_expires_at( $spend, $this->customer( 50, '2026-07-01 00:00:00' ), null, self::NOW )
		);
	}

	private function policy( array $overrides ): ExpiryPolicy {
		return new ExpiryPolicy(
			array_merge(
				[
					'enabled'         => true,
					'mode'            => 'inactivity',
					'inactivity_days' => 365,
					'batch_months'    => 12,
					'spend_order'     => 'oldest',
					'on_shorten'      => 'keep',
					'reminder_days'   => 30,
				],
				$overrides
			)
		);
	}

	private function customer( int $balance, ?string $expires_at, ?string $last_activity_at = null ): Customer {
		return new Customer( 7, $balance, 0, $balance, 0, $last_activity_at, $expires_at, null, '2024-01-01 00:00:00' );
	}

	private function row( string $type, int $points, string $created_at ): LedgerEntry {
		return new LedgerEntry( 1, 7, $points, 0, $type, 'test', null, null, null, null, null, null, null, $created_at );
	}
}
