<?php
namespace YayLoyalty\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\LedgerType;

/** CORRECTION must stay out of every aggregate-affecting set: it moves balance only. */
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

    public function test_lifetime_earned_types_exclude_correction(): void {
        $this->assertSame( [ LedgerType::EARN, LedgerType::REFUND_EARN, LedgerType::ADJUST ], LedgerType::lifetime_earned_types() );
    }

    public function test_manual_adjustment_covers_adjust_and_correction_only(): void {
        $this->assertTrue( LedgerType::is_manual_adjustment( LedgerType::ADJUST ) );
        $this->assertTrue( LedgerType::is_manual_adjustment( LedgerType::CORRECTION ) );
        $this->assertFalse( LedgerType::is_manual_adjustment( LedgerType::EARN ) );
    }

    public function test_adjust_still_affects_lifetime_earned(): void {
        $this->assertTrue( LedgerType::affects_lifetime_earned( LedgerType::ADJUST ) );
    }
}
