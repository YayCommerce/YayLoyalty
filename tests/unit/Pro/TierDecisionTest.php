<?php
namespace YayLoyalty\Tests\Unit\Pro;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Models\Tier;
use YayLoyalty\Pro\Services\TierDecision;

/** The tier move rule: which tier a total reaches, which ledger rows may move a customer, and in which direction. */
final class TierDecisionTest extends TestCase {

    public function test_target_is_highest_threshold_reached(): void {
        $this->assertSame( 2, TierDecision::target( $this->live(), 810, null )->get_id() );
        $this->assertSame( 2, TierDecision::target( $this->live(), 800, null )->get_id() );
        $this->assertSame( 1, TierDecision::target( $this->live(), 799, null )->get_id() );
    }

    public function test_target_is_null_below_lowest_threshold(): void {
        $live = [ $this->tier( 2, 800, 1 ), $this->tier( 3, 3000, 2 ) ];

        $this->assertNull( TierDecision::target( $live, 500, null ) );
    }

    public function test_floor_wins_when_it_outranks_qualify(): void {
        $this->assertSame( 3, TierDecision::target( $this->live(), 100, $this->tier( 3, 3000, 3 ) )->get_id() );
        $this->assertSame( 3, TierDecision::target( $this->live(), 3500, $this->tier( 2, 800, 2 ) )->get_id() );
    }

    public function test_ledger_policy(): void {
        $this->assertSame(
            [
                'move'   => 'up',
                'reason' => 'earn',
            ],
            TierDecision::ledger_policy( 'earn', 'order', 30 )
        );
        $this->assertSame(
            [
                'move'   => 'up',
                'reason' => 'adjustment',
            ],
            TierDecision::ledger_policy( 'adjust', 'manual', 10 )
        );
        $this->assertSame(
            [
                'move'   => 'to_target',
                'reason' => 'refund',
            ],
            TierDecision::ledger_policy( 'refund_earn', 'order', -30 )
        );
        $this->assertSame(
            [
                'move'   => 'to_target',
                'reason' => 'adjustment',
            ],
            TierDecision::ledger_policy( 'adjust', 'manual', -10 )
        );
        $this->assertSame(
            [
                'move'   => 'up',
                'reason' => 'refund',
            ],
            TierDecision::ledger_policy( 'refund_earn', 'order', 30 )
        );
        $this->assertNull( TierDecision::ledger_policy( 'earn', 'tier_bonus', 100 ) );
        $this->assertNull( TierDecision::ledger_policy( 'redeem', 'order', -50 ) );
        $this->assertNull( TierDecision::ledger_policy( 'correction', 'manual', 50 ) );
        $this->assertNull( TierDecision::ledger_policy( 'expire', 'expiry', -50 ) );
    }

    public function test_up_only_never_lowers(): void {
        $this->assertFalse( TierDecision::move( 2, 2, $this->tier( 1, 0, 1 ), 'up' )['changed'] );
        $this->assertFalse( TierDecision::move( 2, 2, null, 'up' )['changed'] );

        $up = TierDecision::move( 1, 1, $this->tier( 2, 800, 2 ), 'up' );

        $this->assertSame( [ true, 2, true ], [ $up['changed'], $up['to']->get_id(), $up['is_upgrade'] ] );
    }

    public function test_to_target_lowers_and_clears(): void {
        $down = TierDecision::move( 2, 2, $this->tier( 1, 0, 1 ), 'to_target' );
        $this->assertSame( [ true, 1, false ], [ $down['changed'], $down['to']->get_id(), $down['is_upgrade'] ] );

        $none = TierDecision::move( 2, 2, null, 'to_target' );
        $this->assertSame( [ true, null, false ], [ $none['changed'], $none['to'], $none['is_upgrade'] ] );

        $this->assertFalse( TierDecision::move( 2, 2, $this->tier( 2, 800, 2 ), 'to_target' )['changed'] );
    }

    public function test_deleted_current_counts_as_rank_zero(): void {
        // Current id 9 is a deleted tier: rank 0, so the move to Bronze is a change and an upgrade by rank.
        $move = TierDecision::move( 9, 0, $this->tier( 1, 0, 1 ), 'to_target' );

        $this->assertSame( [ true, 1, true ], [ $move['changed'], $move['to']->get_id(), $move['is_upgrade'] ] );
    }

    public function test_null_current_to_null_target_is_no_change(): void {
        $this->assertFalse( TierDecision::move( null, 0, null, 'to_target' )['changed'] );
    }

    /** @return Tier[] Bronze 0 rank 1, Silver 800 rank 2, Gold 3000 rank 3; ids 1, 2, 3. */
    public function test_rolling_up_starts_a_hold_from_the_window(): void {
        $r = TierDecision::rolling_move( 1, 1, $this->silver(), null, null, false, TierDecision::KIND_UP );

        $this->assertSame( [ true, 2, true, TierDecision::HOLD_START ], [ $r['changed'], $r['to']->get_id(), $r['is_upgrade'], $r['hold'] ] );
    }

    public function test_rolling_up_to_the_floor_clears_the_hold(): void {
        $r = TierDecision::rolling_move( 1, 1, $this->bronze(), null, $this->gold(), false, TierDecision::KIND_UP );

        $this->assertSame( [ 3, TierDecision::HOLD_CLEAR ], [ $r['to']->get_id(), $r['hold'] ] );
    }

    public function test_rolling_up_without_upgrade_keeps(): void {
        $r = TierDecision::rolling_move( 3, 3, $this->silver(), null, null, true, TierDecision::KIND_UP );

        $this->assertSame( [ false, TierDecision::HOLD_KEEP ], [ $r['changed'], $r['hold'] ] );
    }

    public function test_rolling_down_in_hold_keeps_while_qualifying_reaches(): void {
        $r = TierDecision::rolling_move( 3, 3, $this->bronze(), $this->gold(), null, true, TierDecision::KIND_DOWN );

        $this->assertSame( [ false, TierDecision::HOLD_KEEP ], [ $r['changed'], $r['hold'] ] );
    }

    public function test_rolling_down_in_hold_drops_to_qualifying_and_keeps_dates(): void {
        $r = TierDecision::rolling_move( 3, 3, $this->bronze(), $this->silver(), null, true, TierDecision::KIND_DOWN );

        $this->assertSame( [ 2, TierDecision::HOLD_KEEP ], [ $r['to']->get_id(), $r['hold'] ] );
    }

    public function test_rolling_down_in_hold_window_tie_restarts(): void {
        $r = TierDecision::rolling_move( 3, 3, $this->silver(), $this->silver(), null, true, TierDecision::KIND_DOWN );

        $this->assertSame( [ 2, TierDecision::HOLD_START ], [ $r['to']->get_id(), $r['hold'] ] );
    }

    public function test_rolling_down_in_hold_floor_wins_and_clears(): void {
        $r = TierDecision::rolling_move( 3, 3, $this->bronze(), $this->bronze(), $this->silver(), true, TierDecision::KIND_DOWN );

        $this->assertSame( [ 2, TierDecision::HOLD_CLEAR ], [ $r['to']->get_id(), $r['hold'] ] );
    }

    public function test_rolling_down_without_hold_goes_to_window(): void {
        $r = TierDecision::rolling_move( 3, 3, null, null, null, false, TierDecision::KIND_DOWN );

        $this->assertSame( [ true, null, TierDecision::HOLD_CLEAR ], [ $r['changed'], $r['to'], $r['hold'] ] );
    }

    public function test_rolling_to_target_renews_unchanged(): void {
        $r = TierDecision::rolling_move( 2, 2, $this->silver(), null, null, false, TierDecision::KIND_TO_TARGET );

        $this->assertSame( [ false, TierDecision::HOLD_START ], [ $r['changed'], $r['hold'] ] );
    }

    public function test_rolling_to_target_skips_several_tiers(): void {
        $r = TierDecision::rolling_move( 3, 3, $this->bronze(), null, null, false, TierDecision::KIND_TO_TARGET );

        $this->assertSame( [ 1, false, TierDecision::HOLD_START ], [ $r['to']->get_id(), $r['is_upgrade'], $r['hold'] ] );
    }

    public function test_rolling_to_target_floor_tie_clears(): void {
        $r = TierDecision::rolling_move( 2, 2, $this->silver(), null, $this->silver(), false, TierDecision::KIND_TO_TARGET );

        $this->assertSame( [ false, TierDecision::HOLD_CLEAR ], [ $r['changed'], $r['hold'] ] );
    }

    public function test_rolling_hold_only(): void {
        $this->assertSame( TierDecision::HOLD_START, TierDecision::rolling_move( 2, 2, $this->bronze(), null, null, false, TierDecision::KIND_HOLD_ONLY )['hold'] );
        $this->assertSame( TierDecision::HOLD_CLEAR, TierDecision::rolling_move( 3, 3, $this->bronze(), null, $this->gold(), false, TierDecision::KIND_HOLD_ONLY )['hold'] );
        $this->assertSame( TierDecision::HOLD_CLEAR, TierDecision::rolling_move( null, 0, null, null, null, false, TierDecision::KIND_HOLD_ONLY )['hold'] );
        $this->assertFalse( TierDecision::rolling_move( 2, 2, $this->gold(), null, null, false, TierDecision::KIND_HOLD_ONLY )['changed'] );
    }

    private function bronze(): Tier {
        return $this->tier( 1, 0, 1 );
    }

    private function silver(): Tier {
        return $this->tier( 2, 800, 2 );
    }

    private function gold(): Tier {
        return $this->tier( 3, 3000, 3 );
    }

    private function live(): array {
        return [ $this->tier( 1, 0, 1 ), $this->tier( 2, 800, 2 ), $this->tier( 3, 3000, 3 ) ];
    }

    private function tier( int $id, int $threshold, int $rank ): Tier {
        return new Tier( $id, 'Tier ' . $id, $threshold, $rank, '1.00', 0, '#000000', '2026-11-04 00:00:00', '2026-11-04 00:00:00', null );
    }
}
