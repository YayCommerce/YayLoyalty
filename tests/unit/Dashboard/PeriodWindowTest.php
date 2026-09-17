<?php
namespace YayLoyalty\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Services\Dashboard\PeriodWindow;

/**
 * Window = the N site-timezone calendar days ending today; previous = the N
 * days before that. UTC bounds are derived from the site zone, never the
 * other way round.
 */
final class PeriodWindowTest extends TestCase {

    private function saigon( string $local ): \DateTimeImmutable {
        return new \DateTimeImmutable( $local, new \DateTimeZone( 'Asia/Saigon' ) );
    }

    public function test_30_day_window_in_saigon(): void {
        $window = PeriodWindow::for_range( 30, $this->saigon( '2026-09-16 08:00:00' ) );

        $this->assertSame( 30, $window->range() );
        $this->assertSame( '2026-08-17 17:00:00', $window->from_utc() );
        $this->assertSame( '2026-09-16 17:00:00', $window->to_utc() );
        $this->assertSame( '2026-07-18 17:00:00', $window->prev_from_utc() );
        $this->assertSame( '2026-08-17 17:00:00', $window->prev_to_utc() );
        $this->assertSame( 7 * 3600, $window->utc_offset_seconds() );
    }

    public function test_days_are_site_calendar_dates_of_length_range(): void {
        $days = PeriodWindow::for_range( 30, $this->saigon( '2026-09-16 08:00:00' ) )->days();

        $this->assertCount( 30, $days );
        $this->assertSame( '2026-08-18', $days[0] );
        $this->assertSame( '2026-09-16', $days[29] );
    }

    public function test_seven_and_ninety_day_ranges(): void {
        $now = $this->saigon( '2026-09-16 23:30:00' );

        $this->assertCount( 7, PeriodWindow::for_range( 7, $now )->days() );
        $this->assertSame( '2026-09-10', PeriodWindow::for_range( 7, $now )->days()[0] );
        $this->assertCount( 90, PeriodWindow::for_range( 90, $now )->days() );
        $this->assertSame( '2026-06-19', PeriodWindow::for_range( 90, $now )->days()[0] );
    }

    public function test_late_evening_utc_still_uses_site_today(): void {
        // 23:30 in Saigon is 16:30 UTC the same day; "today" is the site day.
        $window = PeriodWindow::for_range( 7, $this->saigon( '2026-09-16 23:30:00' ) );

        $this->assertSame( '2026-09-16', $window->days()[6] );
        $this->assertSame( '2026-09-16 17:00:00', $window->to_utc() );
    }

    public function test_unsupported_range_throws(): void {
        $this->expectException( \InvalidArgumentException::class );

        PeriodWindow::for_range( 14, $this->saigon( '2026-09-16 08:00:00' ) );
    }
}
