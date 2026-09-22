<?php
namespace YayLoyalty\Tests\Unit\Earning;

use PHPUnit\Framework\TestCase;

/** Staging is keyed on the meta's presence; release on a separate flag. Needs WC_Order — not a plain unit test until a harness exists. */
final class PendingPointsTest extends TestCase {

    public function test_stage_is_idempotent_keyed_on_the_pending_meta_being_present(): void {
        $this->markTestIncomplete( 'stage() twice: second returns false, meta and customers.pending_points unchanged.' );
    }

    public function test_stage_is_safe_to_call_from_several_hooks(): void {
        $this->markTestIncomplete( 'stage() from checkout_order_created and new_order for one order: one staging.' );
    }

    public function test_release_happens_exactly_once_guarded_by_the_release_flag(): void {
        $this->markTestIncomplete( 'release() twice: first returns the figure and sets the flag, second returns 0; pending_points moved once.' );
    }

    public function test_release_without_prior_stage_is_a_no_op(): void {
        $this->markTestIncomplete( 'release() with no pending meta returns 0 and writes nothing — award must not depend on staging.' );
    }

    public function test_release_on_cancel_then_on_award_moves_pending_once(): void {
        $this->markTestIncomplete( 'release() from on_cancelled then from award: second returns 0; the flag is shared.' );
    }
}
