<?php
namespace YayLoyalty\Tests\Unit\Earning;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\ClawbackMath;

/** Points owed back are recomputed from the cumulative refunded base, so rounding never strands or over-claws. */
final class ClawbackMathTest extends TestCase {

    public function test_three_third_refunds_claw_back_33_66_then_exactly_100(): void {
        $this->assertSame( 33, ClawbackMath::owed( 100, 30.0, 90.0, false ) );
        $this->assertSame( 66, ClawbackMath::owed( 100, 60.0, 90.0, false ) );
        $this->assertSame( 100, ClawbackMath::owed( 100, 90.0, 90.0, false ) );
    }

    public function test_a_refund_that_removes_no_base_owes_nothing(): void {
        $this->assertSame( 0, ClawbackMath::owed( 100, 0.0, 90.0, false ) );
    }

    public function test_a_fully_refunded_order_owes_everything_whatever_the_ratio(): void {
        $this->assertSame( 100, ClawbackMath::owed( 100, 10.0, 90.0, true ) );
    }

    public function test_no_original_base_owes_nothing(): void {
        $this->assertSame( 0, ClawbackMath::owed( 100, 50.0, 0.0, false ) );
    }

    public function test_an_over_refund_is_capped_at_what_was_earned(): void {
        $this->assertSame( 100, ClawbackMath::owed( 100, 120.0, 90.0, false ) );
    }
}
