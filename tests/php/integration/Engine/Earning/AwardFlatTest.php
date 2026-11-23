<?php
namespace YayTests\Integration\Engine\Earning;

use WP_UnitTestCase;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

/**
 * A flat award passes the gates, writes one keyed row carrying the context's order id, and a repeat is a duplicate.
 *
 * @group P-4
 */
final class AwardFlatTest extends WP_UnitTestCase {

	private const ORDER_ID = 987654;

	/** @var int */
	private $user_id;

	public function set_up() {
		parent::set_up();
		$this->user_id = self::factory()->user->create( [ 'role' => 'customer' ] );
	}

	public function test_the_row_is_written_once_with_the_contexts_order_id() {
		$first  = $this->award( 41 );
		$second = $this->award( 41 );

		$this->assertSame( Outcome::AWARDED, $first->get_status() );
		$this->assertSame( 1000, $first->get_entry()->get_points() );
		$this->assertSame( Outcome::DUPLICATE, $second->get_status() );

		$rows = ( new LedgerRepository() )->earn_rows_for_order( self::ORDER_ID );

		$this->assertCount( 1, $rows );
		$this->assertSame( LedgerSource::REFERRAL, $rows[0]->get_source() );
		$this->assertSame( $this->user_id, $rows[0]->get_user_id() );
	}

	public function test_a_blocking_gate_writes_nothing() {
		update_user_meta( $this->user_id, ProgramGate::META_OPTED_OUT, 1 );

		$outcome = $this->award( 42 );

		$this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
		$this->assertSame( ProgramGate::REASON_OPTED_OUT, $outcome->get_reason() );
		$this->assertSame( [], ( new LedgerRepository() )->earn_rows_for_order( self::ORDER_ID ) );
	}

	public function test_a_referral_row_is_neither_todays_earning_nor_the_orders_points() {
		$ledger = new LedgerRepository();
		$before = $ledger->points_earned_on_site_day( $this->user_id, Dates::now(), null );

		$this->award( 43 );

		$this->assertSame( $before, $ledger->points_earned_on_site_day( $this->user_id, Dates::now(), null ) );
		$this->assertSame( [], $ledger->points_by_orders( [ self::ORDER_ID ] ) );
	}

	private function award( int $reference_id ): Outcome {
		return EarningService::build()->award_flat(
			CampaignTrigger::REFERRAL,
			ContextFactory::from_user( $this->user_id, Dates::now(), [], self::ORDER_ID ),
			1000,
			LedgerSource::REFERRAL,
			ReferenceType::REFERRAL,
			$reference_id
		);
	}
}
