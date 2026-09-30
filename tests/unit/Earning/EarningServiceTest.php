<?php
namespace YayLoyalty\Tests\Unit\Earning;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Gate;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\CapPolicy;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Tests\Unit\EngineTestCase;

/**
 * The two moments through one service. preview() runs real gates and the
 * real engine over an in-memory repository; award()'s writes are asserted
 * against a real database in tests/integration/Earning/EarningServiceAwardTest.php.
 */
final class EarningServiceTest extends EngineTestCase {

    /**
     * @param Campaign[] $campaigns
     */
    private function engine( array $campaigns ): PointsEngine {
        $repository = $this->repository( $campaigns );

        return new PointsEngine( Registry::with_lite_handlers( $repository ), $repository, new CapPolicy() );
    }

    /**
     * @param Gate[] $gates
     */
    private function service( PointsEngine $engine, array $gates ): EarningService {
        $customers = new CustomerRepository();

        return new EarningService( $engine, new LedgerService( new LedgerRepository(), $customers ), new PendingPoints( $customers ), $gates );
    }

    /** A gate that answers $reason and appends it to $asked, so a test can see which gates ran. */
    private function gate( ?string $reason, \ArrayObject $asked ): Gate {
        return new class( $reason, $asked ) implements Gate {
            private ?string $reason;
            private \ArrayObject $asked;

            public function __construct( ?string $reason, \ArrayObject $asked ) {
                $this->reason = $reason;
                $this->asked  = $asked;
            }

            public function check( string $trigger, Context $ctx ): ?string {
                $this->asked[] = $this->reason;

                return $this->reason;
            }
        };
    }

    public function test_preview_with_no_gate_objection_is_the_engine_answer(): void {
        $engine = $this->engine( [ $this->purchase_campaign() ] );
        $ctx    = $this->context( [ $this->line( 100 ) ] );

        $result = $this->service( $engine, [] )->preview( CampaignTrigger::PURCHASE, $ctx );

        $this->assertEquals( $engine->evaluate( CampaignTrigger::PURCHASE, $ctx ), $result );
        $this->assertSame( 100, $result->get_points() );
    }

    public function test_gate_chain_stops_at_the_first_reason_and_reports_it(): void {
        $asked = new \ArrayObject();
        $gates = [
            $this->gate( null, $asked ),
            $this->gate( ProgramGate::REASON_PROGRAM_PAUSED, $asked ),
            $this->gate( ProgramGate::REASON_OPTED_OUT, $asked ),
        ];

        $result = $this->service( $this->engine( [ $this->purchase_campaign() ] ), $gates )
            ->preview( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 100 ) ] ) );

        $this->assertFalse( $result->is_awarded() );
        $this->assertSame( ProgramGate::REASON_PROGRAM_PAUSED, $result->get_reason() );
        $this->assertSame( [ null, ProgramGate::REASON_PROGRAM_PAUSED ], $asked->getArrayCopy() );
    }

    public function test_preview_evaluates_at_the_context_occurred_at_not_now(): void {
        $ended_after_purchase = $this->purchase_campaign( [], 0, 1, Status::ACTIVE, null, '2026-06-16 00:00:00' );

        $result = $this->service( $this->engine( [ $ended_after_purchase ] ), [] )
            ->preview( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 100 ) ] ) );

        $this->assertTrue( $result->is_awarded() );
    }

    /** The unit harness has no $wpdb, so reaching LedgerService::earn() here would error: skipped proves it was never called. */
    public function test_award_returns_skipped_with_the_gate_reason_and_writes_nothing(): void {
        $gate    = $this->gate( ProgramGate::REASON_PROGRAM_PAUSED, new \ArrayObject() );
        $outcome = $this->service( $this->engine( [ $this->purchase_campaign() ] ), [ $gate ] )
            ->award( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 100 ) ] ) );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( ProgramGate::REASON_PROGRAM_PAUSED, $outcome->get_reason() );
        $this->assertNull( $outcome->get_entry() );
    }
}
