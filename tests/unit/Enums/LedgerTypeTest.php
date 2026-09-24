<?php
namespace YayLoyalty\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\LedgerType;

/**
 * CORRECTION (a manual adjustment with "adjust lifetime totals" off) must
 * stay out of every aggregate-affecting set — that exclusion is the entire
 * mechanism apply_aggregates() relies on to leave lifetime_earned untouched.
 */
final class LedgerTypeTest extends TestCase {

    public function test_correction_is_a_known_type(): void {
        $this->assertContains( LedgerType::CORRECTION, LedgerType::all() );
        $this->assertTrue( LedgerType::is_valid( LedgerType::CORRECTION ) );
    }

    public function test_correction_does_not_affect_lifetime_earned(): void {
        $this->assertFalse( LedgerType::affects_lifetime_earned( LedgerType::CORRECTION ) );
    }

    public function test_correction_does_not_affect_lifetime_redeemed(): void {
        $this->assertFalse( LedgerType::affects_lifetime_redeemed( LedgerType::CORRECTION ) );
    }

    public function test_correction_is_not_activity(): void {
        $this->assertFalse( LedgerType::is_activity( LedgerType::CORRECTION ) );
    }

    /** ADJUST keeps affecting lifetime_earned — only CORRECTION is the balance-only escape hatch. */
    public function test_adjust_still_affects_lifetime_earned(): void {
        $this->assertTrue( LedgerType::affects_lifetime_earned( LedgerType::ADJUST ) );
    }
}
