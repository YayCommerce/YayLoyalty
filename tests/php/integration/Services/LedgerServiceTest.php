<?php
namespace YayTests\Integration\Services;

use WP_UnitTestCase;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * The single writer of balances and lots, against real tables. Each test's writes roll back:
 * Transaction sees the test suite's open transaction and nests under it with savepoints.
 *
 * @group baseline
 */
final class LedgerServiceTest extends WP_UnitTestCase {

	/** @var LedgerService */
	private $ledger;

	/** @var int */
	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->ledger = new LedgerService( new LedgerRepository(), new CustomerRepository(), new CampaignRepository() );

		// Created by an admin, so the built-in signup campaign (self-registration only) awards nothing.
		wp_set_current_user( 1 );
		$this->user_id = self::factory()->user->create();
	}

	public function test_open_lots_always_sum_to_the_balance_floored_at_zero() {
		foreach ( [ 100 => 100, -30 => 70, -90 => -20, 25 => 5 ] as $points => $balance ) {
			$this->ledger->adjust( $this->user_id, $points, 'test', 1 );

			$this->assertSame( $balance, $this->balance(), "after adjusting by $points" );
			$this->assertSame( max( 0, $balance ), $this->open_lot_points(), "lots after adjusting by $points" );
		}
	}

	public function test_a_repeated_award_for_the_same_order_and_trigger_writes_nothing() {
		$first  = $this->ledger->earn( $this->earn_draft( 1234, 40 ) );
		$second = $this->ledger->earn( $this->earn_draft( 1234, 40 ) );

		$this->assertSame( Outcome::AWARDED, $first->get_status() );
		$this->assertSame( Outcome::DUPLICATE, $second->get_status() );
		$this->assertSame( 40, $this->balance() );
		$this->assertSame( 40, $this->open_lot_points() );
	}

	public function test_a_correction_moves_the_balance_but_not_lifetime_earned() {
		$this->ledger->adjust( $this->user_id, 100, 'grant', 1 );
		$this->ledger->adjust( $this->user_id, -40, 'fix', 1, false );

		$customer = ( new CustomerRepository() )->find_by_user_id( $this->user_id );

		$this->assertSame( 60, $customer->get_balance() );
		$this->assertSame( 100, $customer->get_lifetime_earned() );
	}

	private function balance(): int {
		return ( new CustomerRepository() )->find_by_user_id( $this->user_id )->get_balance();
	}

	private function open_lot_points(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(remaining), 0) FROM `' . esc_sql( Table::name( 'lots' ) ) . '` WHERE user_id = %d AND status = %s',
				$this->user_id,
				LotStatus::OPEN
			)
		);
	}

	private function earn_draft( int $order_id, int $points ): LedgerEntry {
		return new LedgerEntry(
			null,
			$this->user_id,
			$points,
			0,
			LedgerType::EARN,
			LedgerSource::ORDER,
			ReferenceType::ORDER,
			$order_id,
			null,
			null,
			$order_id,
			null,
			null,
			Dates::now(),
			IdempotencyKey::for_earn( $this->user_id, ReferenceType::ORDER, $order_id, 'purchase' )
		);
	}
}
