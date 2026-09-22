<?php
namespace YayLoyalty\Tests\Unit\Earning;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;

/** The three shapes the award moment returns and what each exposes. */
final class OutcomeTest extends TestCase {

    private function entry(): LedgerEntry {
        return new LedgerEntry( 7, 42, 100, 100, LedgerType::EARN, LedgerSource::ORDER, 'order', 1234, 1, null, 1234, null, null, '2026-06-15 12:00:00', 'earn:42:order:1234:purchase' );
    }

    public function test_duplicate_is_a_normal_outcome_carrying_the_existing_row(): void {
        $outcome = Outcome::duplicate( $this->entry() );

        $this->assertSame( Outcome::DUPLICATE, $outcome->get_status() );
        $this->assertTrue( $outcome->is_duplicate() );
        $this->assertFalse( $outcome->is_awarded() );
        $this->assertSame( 7, $outcome->get_entry()->get_id() );
        $this->assertSame( '', $outcome->get_reason() );
    }

    public function test_skipped_carries_the_reason_and_no_row(): void {
        $outcome = Outcome::skipped( 'program_paused' );

        $this->assertSame( 'program_paused', $outcome->get_reason() );
        $this->assertNull( $outcome->get_entry() );
        $this->assertNull( $outcome->get_evaluation() );
    }

    public function test_skipped_by_the_engine_keeps_its_evaluation(): void {
        $result  = Result::none( Result::REASON_NO_CAMPAIGNS );
        $outcome = Outcome::skipped( $result->get_reason(), $result );

        $this->assertSame( Result::REASON_NO_CAMPAIGNS, $outcome->get_reason() );
        $this->assertSame( $result, $outcome->get_evaluation() );
    }
}
