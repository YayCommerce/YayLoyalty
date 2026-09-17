<?php
namespace YayLoyalty\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Services\Dashboard\AnomalyDetector;

/**
 * A "jump" is yesterday >= MULTIPLE x the average of the other 29 days, with
 * enough orders and enough history to make the average meaningful. The
 * average excludes yesterday so a spike cannot depress its own multiple.
 */
final class AnomalyDetectorTest extends TestCase {

    private const YESTERDAY = '2026-09-15';

    /**
     * @param int[] $overrides date => points
     * @return array<string, int>
     */
    private function daily( int $baseline, array $overrides = [] ): array {
        $daily = [];
        $day   = new \DateTimeImmutable( '2026-08-17' );

        for ( $i = 0; $i < 30; $i++ ) {
            $date           = $day->format( 'Y-m-d' );
            $daily[ $date ] = $overrides[ $date ] ?? $baseline;
            $day            = $day->modify( '+1 day' );
        }

        return $daily;
    }

    public function test_detects_a_jump(): void {
        $daily  = $this->daily( 6100, [ self::YESTERDAY => 41200 ] );
        $result = ( new AnomalyDetector() )->evaluate( $daily, 214, self::YESTERDAY );

        $this->assertNotNull( $result );
        $this->assertSame( 41200, $result['yesterday_points'] );
        $this->assertSame( 6100, $result['daily_average'] );
        $this->assertSame( 6.8, $result['multiple'] );
    }

    public function test_thresholds_are_public_for_the_spa(): void {
        $this->assertSame( 3.0, AnomalyDetector::MULTIPLE );
        $this->assertSame( 20, AnomalyDetector::MIN_ORDERS );
        $this->assertSame( 7, AnomalyDetector::MIN_HISTORY_DAYS );
    }

    public function test_too_few_orders_is_not_a_jump(): void {
        $daily = $this->daily( 6100, [ self::YESTERDAY => 41200 ] );

        $this->assertNull( ( new AnomalyDetector() )->evaluate( $daily, 5, self::YESTERDAY ) );
    }

    public function test_below_multiple_is_not_a_jump(): void {
        $daily = $this->daily( 6100, [ self::YESTERDAY => 12000 ] );

        $this->assertNull( ( new AnomalyDetector() )->evaluate( $daily, 214, self::YESTERDAY ) );
    }

    public function test_exactly_at_multiple_is_a_jump(): void {
        $daily  = $this->daily( 6100, [ self::YESTERDAY => 18300 ] );
        $result = ( new AnomalyDetector() )->evaluate( $daily, 214, self::YESTERDAY );

        $this->assertSame( 3.0, $result['multiple'] );
    }

    public function test_average_ignores_inactive_days(): void {
        // Weekend-only store: 8 active days at 6100 among zeros. An ordinary
        // 6100 day is exactly average, not a 3x jump over the zero-padded mean.
        $daily = $this->daily( 0, [ self::YESTERDAY => 6100 ] );
        $day   = new \DateTimeImmutable( '2026-09-12' );

        for ( $i = 0; $i < 8; $i++ ) {
            $daily[ $day->format( 'Y-m-d' ) ] = 6100;
            $day                              = $day->modify( '-3 days' );
        }

        $this->assertNull( ( new AnomalyDetector() )->evaluate( $daily, 214, self::YESTERDAY ) );
        $this->assertSame( 3.0, ( new AnomalyDetector() )->evaluate( array_merge( $daily, [ self::YESTERDAY => 18300 ] ), 214, self::YESTERDAY )['multiple'] );
    }

    public function test_exactly_min_history_days_is_enough(): void {
        $daily = $this->daily( 0, [ self::YESTERDAY => 41200 ] );
        $day   = new \DateTimeImmutable( '2026-09-10' );

        for ( $i = 0; $i < 7; $i++ ) {
            $daily[ $day->format( 'Y-m-d' ) ] = 6100;
            $day                              = $day->modify( '-1 day' );
        }

        $this->assertNotNull( ( new AnomalyDetector() )->evaluate( $daily, 214, self::YESTERDAY ) );
    }

    public function test_all_zero_history_is_not_a_jump(): void {
        $daily = $this->daily( 0, [ self::YESTERDAY => 41200 ] );

        $this->assertNull( ( new AnomalyDetector() )->evaluate( $daily, 214, self::YESTERDAY ) );
    }

    public function test_too_little_history_is_not_a_jump(): void {
        $daily = $this->daily( 0, [ self::YESTERDAY => 41200 ] );
        $day   = new \DateTimeImmutable( '2026-09-10' );

        // Only 5 non-zero days before the spike — a brand-new store, not a jump.
        for ( $i = 0; $i < 5; $i++ ) {
            $daily[ $day->format( 'Y-m-d' ) ] = 6100;
            $day                              = $day->modify( '-1 day' );
        }

        $this->assertNull( ( new AnomalyDetector() )->evaluate( $daily, 214, self::YESTERDAY ) );
    }

    public function test_missing_yesterday_is_not_a_jump(): void {
        $this->assertNull( ( new AnomalyDetector() )->evaluate( $this->daily( 6100 ), 214, '2026-12-31' ) );
    }
}
