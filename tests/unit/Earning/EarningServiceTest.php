<?php
namespace YayLoyalty\Tests\Unit\Earning;

use PHPUnit\Framework\TestCase;

/**
 * The two moments through one service. The private gate chain is real;
 * everything else is specification until the bodies land.
 */
final class EarningServiceTest extends TestCase {

    public function test_preview_with_no_gate_objection_is_the_engine_answer(): void {
        $this->markTestIncomplete( 'preview(): every gate null → the engine Result, unchanged.' );
    }

    public function test_gate_chain_stops_at_the_first_reason_and_reports_it(): void {
        $this->markTestIncomplete( 'preview() with gates [pass, program_paused, opted_out]: Result::none( program_paused ); the third gate is never called.' );
    }

    public function test_preview_evaluates_at_the_context_occurred_at_not_now(): void {
        $this->markTestIncomplete( 'A campaign that ended after Context::occurred_at but before now still awards in preview().' );
    }

    public function test_award_reevaluates_at_the_same_order_creation_date_as_the_preview(): void {
        $this->markTestIncomplete( 'award(): same Context, same occurred_at, same Result as preview(); a campaign that ended between purchase and award still pays.' );
    }

    public function test_award_returns_skipped_with_the_gate_reason_and_writes_nothing(): void {
        $this->markTestIncomplete( 'award(): a gate reason becomes Outcome::skipped( reason ); LedgerService::earn() is never reached.' );
    }

    public function test_award_returns_duplicate_as_a_normal_outcome_not_an_exception(): void {
        $this->markTestIncomplete( 'award() twice for one order and trigger: second call is Outcome::duplicate carrying the first row; nothing thrown, aggregates unchanged.' );
    }

    public function test_award_does_not_require_staging_to_have_run(): void {
        $this->markTestIncomplete( 'award() on an order that was never staged: Outcome::awarded; PendingPoints::release() returned 0.' );
    }

    public function test_awarded_hook_fires_after_commit_and_only_when_awarded(): void {
        $this->markTestIncomplete( 'yayloyalty_points_awarded observed after Transaction::run() returns, never inside it, and not for skipped or duplicate.' );
    }

    public function test_one_order_can_award_once_per_trigger(): void {
        $this->markTestIncomplete( 'award( purchase ) then award( first_order ) for one order: two rows with distinct idempotency keys.' );
    }
}
