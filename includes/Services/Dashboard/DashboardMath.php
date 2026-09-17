<?php
namespace YayLoyalty\Services\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard arithmetic, kept pure so every rounding and null rule has a unit
 * test. Rule shared by every helper here: a zero denominator yields null,
 * never 0 — the SPA renders "—" / "no previous period" for null and would
 * otherwise show a misleading 0%.
 */
final class DashboardMath {

    /**
     * Percentage change from $previous to $current, 1 dp; null when there is
     * no previous figure to compare against.
     *
     * The denominator is |previous| so the sign always follows the direction
     * of travel: a signed base (liability's previous period is
     * net_balance - net_delta, which can dip below zero) would otherwise
     * report a rise as a fall.
     *
     * @param int|float $current
     * @param int|float $previous
     */
    public static function pct_delta( $current, $previous ): ?float {
        $previous = (float) $previous;

        if ( 0.0 === $previous ) {
            return null;
        }

        return round( ( (float) $current - $previous ) / abs( $previous ) * 100, 1 );
    }

    /**
     * $numerator as a percentage of $denominator, 1 dp; null when the
     * denominator is zero.
     */
    public static function rate( int $numerator, int $denominator ): ?float {
        if ( 0 === $denominator ) {
            return null;
        }

        return round( $numerator / $denominator * 100, 1 );
    }

    /**
     * One entry per day in $days (same order), every $keys column present and
     * cast to int, missing days zero-filled, rows outside $days dropped.
     *
     * @param array<int, array<string, mixed>> $rows Grouped SQL rows carrying a `date` column.
     * @param string[]                         $days Calendar dates, ascending.
     * @param string[]                         $keys Numeric columns to carry over.
     * @return array<int, array<string, int|string>>
     */
    public static function fill_series( array $rows, array $days, array $keys ): array {
        $by_date = [];

        foreach ( $rows as $row ) {
            $by_date[ (string) ( $row['date'] ?? '' ) ] = $row;
        }

        $series = [];

        foreach ( $days as $date ) {
            $entry = [ 'date' => $date ];

            foreach ( $keys as $key ) {
                $entry[ $key ] = (int) ( $by_date[ $date ][ $key ] ?? 0 );
            }

            $series[] = $entry;
        }

        return $series;
    }

    /**
     * Points expressed in store currency at $points_per_currency_unit, 2 dp;
     * null when no usable conversion rate exists.
     *
     * @param int|float $points_per_currency_unit
     */
    public static function currency_amount( int $points, $points_per_currency_unit ): ?float {
        if ( $points_per_currency_unit <= 0 ) {
            return null;
        }

        return round( $points / $points_per_currency_unit, 2 );
    }
}
