<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Enums\TierMode;
use YayLoyalty\Models\Tier;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Calendar mode's dates: period boundaries, hold dates and the qualifying window behind a hold.
 *
 * A period starts at 00:00:00 wall clock on day 1 of a month, of a quarter's first month or of January, in the site
 * time zone, and covers [start, next start − 1 s]. Every value in and out is a UTC `Y-m-d H:i:s`. Pure: callers pass
 * wp_timezone(), so this runs on plain PHP. TierEvaluator, TierStanding and TierProgress are its only callers.
 */
final class TierCalendar {

    /** The tier was reached in the current period: it is kept through the next one. */
    public const SOURCE_CURRENT = 'current';

    /** The tier was reached in the previous period: it is kept to the end of the current one. */
    public const SOURCE_PREVIOUS = 'previous';

    /**
     * The starts of the period holding $now_utc, of the next one and of the previous one.
     *
     * @return array{current_start: string, next_start: string, previous_start: string}
     */
    public static function bounds( string $now_utc, string $period, \DateTimeZone $tz ): array {
        $local  = self::local( $now_utc, $tz );
        $months = TierMode::calendar_period_months( $period );
        $month  = (int) $local->format( 'n' );

        if ( 1 === $months ) {
            $first = $month;
        } elseif ( 3 === $months ) {
            $first = intdiv( $month - 1, 3 ) * 3 + 1;
        } else {
            $first = 1;
        }

        $start = $local->setDate( (int) $local->format( 'Y' ), $first, 1 )->setTime( 0, 0, 0 );

        return [
            'current_start'  => Dates::to_utc( $start ),
            'next_start'     => Dates::to_utc( self::shift( $start, $months ) ),
            'previous_start' => Dates::to_utc( self::shift( $start, -$months ) ),
        ];
    }

    /**
     * The better of the current and previous period's tier, and which period it came from. A tie goes to current,
     * whose hold runs longer.
     *
     * @return array{0: Tier|null, 1: string}
     */
    public static function pick( ?Tier $current, ?Tier $previous ): array {
        if ( null !== $previous && ( null === $current || $previous->get_tier_rank() > $current->get_tier_rank() ) ) {
            return [ $previous, self::SOURCE_PREVIOUS ];
        }

        return [ $current, self::SOURCE_CURRENT ];
    }

    /**
     * The hold for a tier reached in $source's period: current keeps it through the next period, previous to the end
     * of the current one.
     *
     * @param array{current_start: string, next_start: string, previous_start: string} $bounds From bounds().
     * @return array{from: string, until: string}
     */
    public static function hold_dates( string $source, array $bounds, string $period, \DateTimeZone $tz ): array {
        if ( self::SOURCE_PREVIOUS === $source ) {
            return [
                'from'  => $bounds['current_start'],
                'until' => Dates::offset( -1, $bounds['next_start'] ),
            ];
        }

        $end = self::shift( self::local( $bounds['next_start'], $tz ), TierMode::calendar_period_months( $period ) );

        return [
            'from'  => $bounds['next_start'],
            'until' => Dates::offset( -1, Dates::to_utc( $end ) ),
        ];
    }

    /**
     * The period that earned a hold: the L months before the kept period, where L is the kept period's length.
     *
     * L is read from the stored dates, rounded to whole months, so a later period or time-zone change doesn't move
     * the window. A missing hold start, or one not before its end, falls back to the setting's period ending at
     * $held_until.
     *
     * @return array{from: string, to: string}
     */
    public static function qualifying_window( ?string $held_from, string $held_until, string $period, \DateTimeZone $tz ): array {
        $end = self::local( Dates::offset( 1, $held_until ), $tz );

        if ( null === $held_from || $held_from >= $held_until ) {
            $length = TierMode::calendar_period_months( $period );
            $start  = self::shift( $end, -$length );
        } else {
            $start  = self::local( $held_from, $tz );
            $diff   = $start->diff( $end );
            $length = max( 1, $diff->y * 12 + $diff->m + ( $diff->d >= 15 ? 1 : 0 ) );
        }

        return [
            'from' => Dates::to_utc( self::shift( $start, -$length ) ),
            'to'   => Dates::offset( -1, Dates::to_utc( $start ) ),
        ];
    }

    private static function local( string $utc, \DateTimeZone $tz ): \DateTimeImmutable {
        return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
    }

    /** Moves a wall-clock moment by whole months in its own zone. */
    private static function shift( \DateTimeImmutable $at, int $months ): \DateTimeImmutable {
        return $at->modify( sprintf( '%+d months', $months ) );
    }
}
