<?php
namespace YayLoyalty\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Services\Dashboard\DashboardMath;

/**
 * The series zero-fill; the ratio helpers moved to PointsMathTest.
 */
final class DashboardMathTest extends TestCase {

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
}
