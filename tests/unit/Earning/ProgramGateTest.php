<?php
namespace YayLoyalty\Tests\Unit\Earning;

use PHPUnit\Framework\TestCase;

/** Each rule returns its reason; the first failing rule wins in the documented order. */
final class ProgramGateTest extends TestCase {

    public function test_paused_program_returns_program_paused(): void {
        $this->markTestIncomplete( 'general.enabled = false → REASON_PROGRAM_PAUSED, before any customer check.' );
    }

    public function test_guest_context_returns_no_customer(): void {
        $this->markTestIncomplete( 'Context user_id null → REASON_NO_CUSTOMER.' );
    }

    public function test_opted_out_customer_returns_opted_out(): void {
        $this->markTestIncomplete( 'Opt-out user meta set → REASON_OPTED_OUT.' );
    }

    public function test_excluded_role_returns_excluded_role(): void {
        $this->markTestIncomplete( 'A Context role in the excluded-roles setting → REASON_EXCLUDED_ROLE; null otherwise.' );
    }
}
