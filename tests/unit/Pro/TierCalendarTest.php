<?php
namespace YayLoyalty\Tests\Unit\Pro;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Models\Tier;
use YayLoyalty\Pro\Services\TierCalendar;

/** Calendar period boundaries, hold dates and qualifying windows, in the site time zone's wall clock. */
final class TierCalendarTest extends TestCase {

    public function test_bounds_per_period(): void {
        $this->assertSame(
            [
                'current_start'  => '2025-12-31 17:00:00',
                'next_start'     => '2026-12-31 17:00:00',
                'previous_start' => '2024-12-31 17:00:00',
            ],
            TierCalendar::bounds( '2026-11-11 05:00:00', 'year', $this->hcm() )
        );
        $this->assertSame(
            [
                'current_start'  => '2026-09-30 17:00:00',
                'next_start'     => '2026-12-31 17:00:00',
                'previous_start' => '2026-06-30 17:00:00',
            ],
            TierCalendar::bounds( '2026-11-11 05:00:00', 'quarter', $this->hcm() )
        );
        $this->assertSame(
            [
                'current_start'  => '2026-10-31 17:00:00',
                'next_start'     => '2026-11-30 17:00:00',
                'previous_start' => '2026-09-30 17:00:00',
            ],
            TierCalendar::bounds( '2026-11-11 05:00:00', 'month', $this->hcm() )
        );
    }

    public function test_a_boundary_second_belongs_to_the_new_period(): void {
        $this->assertSame( '2025-12-31 17:00:00', TierCalendar::bounds( '2025-12-31 17:00:00', 'year', $this->hcm() )['current_start'] );
        $this->assertSame( '2024-12-31 17:00:00', TierCalendar::bounds( '2025-12-31 16:59:59', 'year', $this->hcm() )['current_start'] );
    }

    public function test_bounds_follow_wall_clock_across_dst(): void {
        $this->assertSame(
            [
                'current_start'  => '2027-01-01 05:00:00',
                'next_start'     => '2027-04-01 04:00:00',
                'previous_start' => '2026-10-01 04:00:00',
            ],
            TierCalendar::bounds( '2027-02-10 12:00:00', 'quarter', $this->ny() )
        );
    }

    public function test_hold_dates_per_source(): void {
        $year = TierCalendar::bounds( '2026-11-11 05:00:00', 'year', $this->hcm() );

        $this->assertSame(
            [
                'from'  => '2026-12-31 17:00:00',
                'until' => '2027-12-31 16:59:59',
            ],
            TierCalendar::hold_dates( 'current', $year, 'year', $this->hcm() )
        );
        $this->assertSame(
            [
                'from'  => '2025-12-31 17:00:00',
                'until' => '2026-12-31 16:59:59',
            ],
            TierCalendar::hold_dates( 'previous', $year, 'year', $this->hcm() )
        );
        $this->assertSame(
            [
                'from'  => '2026-11-30 17:00:00',
                'until' => '2026-12-31 16:59:59',
            ],
            TierCalendar::hold_dates( 'current', TierCalendar::bounds( '2026-11-11 05:00:00', 'month', $this->hcm() ), 'month', $this->hcm() )
        );
        $this->assertSame(
            [
                'from'  => '2026-12-31 17:00:00',
                'until' => '2027-03-31 16:59:59',
            ],
            TierCalendar::hold_dates( 'current', TierCalendar::bounds( '2026-11-11 05:00:00', 'quarter', $this->hcm() ), 'quarter', $this->hcm() )
        );
        // Spec AC 15.
        $this->assertSame(
            [
                'from'  => '2027-01-01 05:00:00',
                'until' => '2027-04-01 03:59:59',
            ],
            TierCalendar::hold_dates( 'current', TierCalendar::bounds( '2026-11-11 12:00:00', 'quarter', $this->ny() ), 'quarter', $this->ny() )
        );
    }

    public function test_qualifying_window_is_the_period_that_earned_the_hold(): void {
        $this->assertSame(
            [
                'from' => '2025-12-31 17:00:00',
                'to'   => '2026-12-31 16:59:59',
            ],
            TierCalendar::qualifying_window( '2026-12-31 17:00:00', '2027-12-31 16:59:59', 'year', $this->hcm() )
        );
        $this->assertSame(
            [
                'from' => '2026-10-01 04:00:00',
                'to'   => '2027-01-01 04:59:59',
            ],
            TierCalendar::qualifying_window( '2027-01-01 05:00:00', '2027-04-01 03:59:59', 'quarter', $this->ny() )
        );
    }

    public function test_qualifying_length_survives_a_time_zone_and_period_change(): void {
        // A yearly hold written in HCM, read in NY with the period now `month`: L stays 12.
        $this->assertSame(
            [
                'from' => '2025-12-31 17:00:00',
                'to'   => '2026-12-31 16:59:59',
            ],
            TierCalendar::qualifying_window( '2026-12-31 17:00:00', '2027-12-31 16:59:59', 'month', $this->ny() )
        );
    }

    public function test_bad_hold_uses_the_setting(): void {
        $expected = [
            'from' => '2026-06-30 17:00:00',
            'to'   => '2026-09-30 16:59:59',
        ];

        $this->assertSame( $expected, TierCalendar::qualifying_window( null, '2026-12-31 16:59:59', 'quarter', $this->hcm() ) );
        $this->assertSame( $expected, TierCalendar::qualifying_window( '2027-01-01 00:00:00', '2026-12-31 16:59:59', 'quarter', $this->hcm() ) );
    }

    public function test_pick_prefers_rank_then_current(): void {
        $silver = $this->tier( 2, 2 );
        $gold   = $this->tier( 3, 3 );

        $this->assertSame( [ $silver, 'current' ], TierCalendar::pick( $silver, $this->tier( 2, 2 ) ) );
        $this->assertSame( [ $gold, 'previous' ], TierCalendar::pick( $silver, $gold ) );
        $this->assertSame( [ $gold, 'current' ], TierCalendar::pick( $gold, null ) );
        $this->assertSame( [ null, 'current' ], TierCalendar::pick( null, null ) );
    }

    /** UTC+7 with no DST. */
    private function hcm(): \DateTimeZone {
        return new \DateTimeZone( 'Asia/Ho_Chi_Minh' );
    }

    private function ny(): \DateTimeZone {
        return new \DateTimeZone( 'America/New_York' );
    }

    private function tier( int $id, int $rank ): Tier {
        return new Tier( $id, 'Tier ' . $id, 0, $rank, '1.00', 0, '#000000', '2026-11-04 00:00:00', '2026-11-04 00:00:00', null );
    }
}
