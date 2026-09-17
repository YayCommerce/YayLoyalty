<?php
namespace YayLoyalty\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Services\Dashboard\DashboardMath;

/**
 * Zero denominators never produce 0% — they produce null so the SPA can say
 * "no previous period" / "—" instead of a misleading figure.
 */
final class DashboardMathTest extends TestCase {

    public function test_pct_delta(): void {
        $this->assertSame( 10.0, DashboardMath::pct_delta( 110, 100 ) );
        $this->assertSame( -10.0, DashboardMath::pct_delta( 90, 100 ) );
        $this->assertSame( -66.7, DashboardMath::pct_delta( 1, 3 ) );
        $this->assertSame( 0.0, DashboardMath::pct_delta( 100, 100 ) );
    }

    public function test_pct_delta_follows_direction_of_travel_on_a_negative_base(): void {
        $this->assertSame( 150.0, DashboardMath::pct_delta( 50, -100 ) );
        $this->assertSame( -50.0, DashboardMath::pct_delta( -150, -100 ) );
    }

    public function test_pct_delta_is_null_without_a_previous_period(): void {
        $this->assertNull( DashboardMath::pct_delta( 5, 0 ) );
        $this->assertNull( DashboardMath::pct_delta( 0, 0 ) );
    }

    public function test_rate(): void {
        $this->assertSame( 17.8, DashboardMath::rate( 212, 1190 ) );
        $this->assertSame( 0.0, DashboardMath::rate( 0, 10 ) );
        $this->assertNull( DashboardMath::rate( 0, 0 ) );
    }

    public function test_fill_series_zero_fills_and_drops_rows_outside_the_window(): void {
        $days = [ '2026-09-14', '2026-09-15', '2026-09-16' ];
        $rows = [
            [
                'date'     => '2026-09-15',
                'issued'   => 120,
                'redeemed' => 40,
            ],
            [
                'date'     => '2026-09-01',
                'issued'   => 999,
                'redeemed' => 999,
            ],
        ];

        $series = DashboardMath::fill_series( $rows, $days, [ 'issued', 'redeemed' ] );

        $this->assertSame(
            [
                [
                    'date'     => '2026-09-14',
                    'issued'   => 0,
                    'redeemed' => 0,
                ],
                [
                    'date'     => '2026-09-15',
                    'issued'   => 120,
                    'redeemed' => 40,
                ],
                [
                    'date'     => '2026-09-16',
                    'issued'   => 0,
                    'redeemed' => 0,
                ],
            ],
            $series
        );
    }

    public function test_fill_series_casts_sql_strings_to_int(): void {
        $series = DashboardMath::fill_series(
            [
                [
                    'date'   => '2026-09-16',
                    'issued' => '12',
                ],
            ],
            [ '2026-09-16' ],
            [ 'issued' ]
        );

        $this->assertSame( 12, $series[0]['issued'] );
    }

    public function test_currency_amount(): void {
        $this->assertSame( 8715.0, DashboardMath::currency_amount( 871500, 100 ) );
        $this->assertSame( 0.0, DashboardMath::currency_amount( 0, 100 ) );
        $this->assertSame( 12.35, DashboardMath::currency_amount( 1235, 100 ) );
    }

    public function test_currency_amount_is_null_without_a_conversion_rate(): void {
        $this->assertNull( DashboardMath::currency_amount( 500, 0 ) );
        $this->assertNull( DashboardMath::currency_amount( 500, -1 ) );
    }
}
