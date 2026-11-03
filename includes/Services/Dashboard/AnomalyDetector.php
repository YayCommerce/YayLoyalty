<?php
namespace YayLoyalty\Services\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Flags a one-day points jump: yesterday at MULTIPLE x the average of the
 * other active days, backed by MIN_ORDERS orders (not one manual
 * adjustment), with MIN_HISTORY_DAYS of history (not a launch week). Only
 * non-zero days enter the average, or a weekend-only store would flag
 * every Saturday. Yesterday is excluded so a spike cannot depress its own multiple.
 */
final class AnomalyDetector {

    /** Yesterday must reach this many times the daily average. */
    public const MULTIPLE = 3.0;

    /** Orders that must back the spike. */
    public const MIN_ORDERS = 20;

    /** Non-zero history days needed before an average means anything. */
    public const MIN_HISTORY_DAYS = 7;

    /**
     * @param array<string, int> $daily_issued `Y-m-d` => points issued, the full 30-day series.
     * @return array{multiple: float, yesterday_points: int, active_day_average: int}|null
     */
    public function evaluate( array $daily_issued, int $yesterday_orders, string $yesterday ): ?array {
        if ( ! array_key_exists( $yesterday, $daily_issued ) || $yesterday_orders < self::MIN_ORDERS ) {
            return null;
        }

        $yesterday_points = (int) $daily_issued[ $yesterday ];
        $history          = $daily_issued;
        unset( $history[ $yesterday ] );

        $active = array_filter( $history, static fn( $points ): bool => (int) $points > 0 );

        if ( count( $active ) < self::MIN_HISTORY_DAYS ) {
            return null;
        }

        $average = array_sum( $active ) / count( $active );

        if ( $yesterday_points < self::MULTIPLE * $average ) {
            return null;
        }

        return [
            'multiple'           => round( $yesterday_points / $average, 1 ),
            'yesterday_points'   => $yesterday_points,
            'active_day_average' => (int) round( $average ),
        ];
    }
}
