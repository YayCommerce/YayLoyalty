<?php
namespace YayLoyalty\Pro\Birthday;

defined( 'ABSPATH' ) || exit;

/**
 * Birthday date maths on site-calendar days. A Feb 29 birthday is kept on Feb 28 in other years,
 * so it is paid every year rather than one year in four.
 */
final class BirthdayWindow {

    /**
     * The next occurrence of a birthday on or after $site_today: its year, which keys the award, and the days to it.
     *
     * @return array{year: int, days_until: int}
     */
    public static function next( int $month, int $day, \DateTimeImmutable $site_today ): array {
        $today = $site_today->setTime( 0, 0 );
        $year  = (int) $today->format( 'Y' );
        $date  = self::occurrence( $month, $day, $year, $today );

        if ( $date < $today ) {
            ++$year;
            $date = self::occurrence( $month, $day, $year, $today );
        }

        return [
            'year'       => $year,
            'days_until' => (int) $today->diff( $date )->days,
        ];
    }

    /**
     * Every birthday whose next occurrence is 0 to $days_before days from $site_today.
     *
     * @return array<int, array{month: int, day: int}>
     */
    public static function due_days( \DateTimeImmutable $site_today, int $days_before ): array {
        $today = $site_today->setTime( 0, 0 );
        $days  = [];

        for ( $offset = 0; $offset <= $days_before; $offset++ ) {
            $date  = $today->modify( '+' . $offset . ' days' );
            $month = (int) $date->format( 'n' );
            $day   = (int) $date->format( 'j' );

            $days[] = [
                'month' => $month,
                'day'   => $day,
            ];

            if ( 2 === $month && 28 === $day && ! self::is_leap( (int) $date->format( 'Y' ) ) ) {
                $days[] = [
                    'month' => 2,
                    'day'   => 29,
                ];
            }
        }

        return $days;
    }

    private static function occurrence( int $month, int $day, int $year, \DateTimeImmutable $today ): \DateTimeImmutable {
        if ( 2 === $month && 29 === $day && ! self::is_leap( $year ) ) {
            $day = 28;
        }

        return $today->setDate( $year, $month, $day );
    }

    private static function is_leap( int $year ): bool {
        return checkdate( 2, 29, $year );
    }
}
