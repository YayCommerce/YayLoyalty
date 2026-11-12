<?php
namespace YayTests\Unit\Services;

use Brain\Monkey\Functions;
use Mockery;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Services\LotService;
use YayTests\Unit\TestCase;

/**
 * Lots must sum to max(0, balance): what a row opens or takes depends on the balance before it.
 *
 * @group baseline
 */
final class LotServiceTest extends TestCase {

	private const AT = '2026-06-01 00:00:00';

	/** @var LotRepository|\Mockery\MockInterface */
	private $lots;

	protected function set_up() {
		parent::set_up();
		$this->given_settings( [] );
		$this->lots = Mockery::mock( LotRepository::class );
	}

	public function test_a_positive_row_opens_a_lot_only_for_the_part_above_zero() {
		$this->lots->shouldReceive( 'open' )->once()->with( 11, 7, 20, 20, self::AT, null );

		$this->service()->record( $this->row( 11, 50 ), -30, null );
	}

	public function test_a_positive_row_that_leaves_the_balance_negative_opens_nothing() {
		$this->lots->shouldNotReceive( 'open' );

		$this->service()->record( $this->row( 11, 50 ), -60, null );
	}

	public function test_a_restored_take_keeps_the_dates_of_the_lot_it_came_from() {
		$this->lots->shouldReceive( 'first_lot_taken_by' )->with( 5 )->andReturn( [ 'earned_at' => '2026-01-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00' ] );
		$this->lots->shouldReceive( 'open' )->once()->with( 11, 7, 10, 10, '2026-01-01 00:00:00', '2027-01-01 00:00:00' );

		$this->service()->record( $this->row( 11, 10 ), 40, 5 );
	}

	public function test_a_spend_takes_from_its_source_lot_first_then_oldest_first() {
		$this->lots->shouldReceive( 'open_lots' )->with( 7, 'ASC' )->andReturn(
			[
				[ 'id' => 1, 'ledger_id' => 100, 'remaining' => 5 ],
				[ 'id' => 2, 'ledger_id' => 200, 'remaining' => 20 ],
				[ 'id' => 3, 'ledger_id' => 300, 'remaining' => 4 ],
			]
		);
		$this->lots->shouldReceive( 'take' )->once()->with( 3, 4, 12, LotStatus::CONSUMED, self::AT )->ordered();
		$this->lots->shouldReceive( 'take' )->once()->with( 1, 5, 12, LotStatus::CONSUMED, self::AT )->ordered();
		$this->lots->shouldReceive( 'take' )->once()->with( 2, 1, 12, LotStatus::CONSUMED, self::AT )->ordered();

		$this->service()->record( $this->row( 12, -10 ), 29, 300 );
	}

	public function test_a_spend_past_zero_takes_only_what_the_balance_covered() {
		$this->lots->shouldReceive( 'open_lots' )->andReturn( [ [ 'id' => 1, 'ledger_id' => 100, 'remaining' => 3 ] ] );
		$this->lots->shouldReceive( 'take' )->once()->with( 1, 3, 12, LotStatus::CONSUMED, self::AT );
		Functions\expect( 'wc_get_logger' )->never();

		$this->service()->record( $this->row( 12, -10 ), 3, null );
	}

	public function test_batch_newest_first_reads_the_lots_newest_first() {
		$this->given_batch_mode_is_available();
		$this->given_settings( [ 'expiry' => [ 'enabled' => true, 'mode' => 'batch', 'spend_order' => 'newest' ] ] );
		$this->lots->shouldReceive( 'open_lots' )->once()->with( 7, 'DESC' )->andReturn( [] );

		$this->service()->record( $this->row( 12, -10 ), 0, null );
	}

	private function service(): LotService {
		return new LotService( $this->lots, Mockery::mock( LedgerRepository::class ) );
	}

	private function row( int $id, int $points ): LedgerEntry {
		return new LedgerEntry( $id, 7, $points, 0, $points > 0 ? 'earn' : 'redeem', 'test', null, null, null, null, null, null, null, self::AT );
	}
}
