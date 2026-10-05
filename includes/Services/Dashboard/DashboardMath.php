<?php
namespace YayLoyalty\Services\Dashboard;

defined( 'ABSPATH' ) || exit;

/** Pure dashboard series helpers. Ratios live in Utils\PointsMath. */
final class DashboardMath {

    /**
     * One entry per day in $days, $keys cast to int, missing days zero-filled, rows outside $days dropped.
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
}
