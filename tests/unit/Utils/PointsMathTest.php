<?php
namespace YayLoyalty\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Utils\PointsMath;

/**
 * Zero denominators never produce 0% — they produce null so the SPA can say
 * "no previous period" / "—" instead of a misleading figure. Rounding to a
 * whole number of points happens once, half away from zero, and an unknown
 * mode rounds down: the safe direction for a merchant's liability.
 */
final class PointsMathTest extends TestCase {

    public function test_round_points_modes(): void {
        $this->assertSame( 49, PointsMath::round_points( 49.99, PointsMath::ROUND_DOWN ) );
        $this->assertSame( 50, PointsMath::round_points( 49.01, PointsMath::ROUND_UP ) );
        $this->assertSame( 50, PointsMath::round_points( 49.5, PointsMath::ROUND_NEAREST ) );
        $this->assertSame( 49, PointsMath::round_points( 49.4, PointsMath::ROUND_NEAREST ) );
    }

    public function test_round_points_unknown_mode_rounds_down(): void {
        $this->assertSame( 75, PointsMath::round_points( 75.9, 'unknown-mode' ) );
    }

    public function test_round_points_none_is_a_marker_not_a_mode(): void {
        // Flat awards carry ROUND_NONE in their breakdown; passing it through
        // round_points must not blow up — it behaves like the default branch.
        $this->assertSame( 75, PointsMath::round_points( 75.0, PointsMath::ROUND_NONE ) );
    }

    public function test_pct_delta(): void {
        $this->assertSame( 10.0, PointsMath::pct_delta( 110, 100 ) );
        $this->assertSame( -10.0, PointsMath::pct_delta( 90, 100 ) );
        $this->assertSame( -66.7, PointsMath::pct_delta( 1, 3 ) );
        $this->assertSame( 0.0, PointsMath::pct_delta( 100, 100 ) );
    }

    public function test_pct_delta_follows_direction_of_travel_on_a_negative_base(): void {
        $this->assertSame( 150.0, PointsMath::pct_delta( 50, -100 ) );
        $this->assertSame( -50.0, PointsMath::pct_delta( -150, -100 ) );
    }

    public function test_pct_delta_is_null_without_a_previous_period(): void {
        $this->assertNull( PointsMath::pct_delta( 5, 0 ) );
        $this->assertNull( PointsMath::pct_delta( 0, 0 ) );
    }

    public function test_pct_delta_honours_decimals(): void {
        $this->assertSame( -23.0, PointsMath::pct_delta( 77, 100, 0 ) );
        $this->assertSame( -66.67, PointsMath::pct_delta( 1, 3, 2 ) );
    }

    public function test_pct_of(): void {
        $this->assertSame( 17.8, PointsMath::pct_of( 212, 1190 ) );
        $this->assertSame( 0.0, PointsMath::pct_of( 0, 10 ) );
        $this->assertNull( PointsMath::pct_of( 0, 0 ) );
    }

    public function test_points_to_currency(): void {
        $this->assertSame( 8715.0, PointsMath::points_to_currency( 871500, 100 ) );
        $this->assertSame( 0.0, PointsMath::points_to_currency( 0, 100 ) );
        $this->assertSame( 12.35, PointsMath::points_to_currency( 1235, 100 ) );
    }

    public function test_points_to_currency_is_null_without_a_conversion_rate(): void {
        $this->assertNull( PointsMath::points_to_currency( 500, 0 ) );
        $this->assertNull( PointsMath::points_to_currency( 500, -1 ) );
    }
}
