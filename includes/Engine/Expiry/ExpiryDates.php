<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** Expiry date maths on UTC `Y-m-d H:i:s` strings. Pure, so the unit suite runs it. */
final class ExpiryDates {

    /** Turning expiry on or shortening it never sets a date sooner than this many days from now. */
    public const GRACE_DAYS = 30;

    public static function inactivity_expires_at( string $base_utc, int $days ): string {
        return Dates::offset( $days * 86400, $base_utc );
    }

    /** $months after the earned date, on the same day of the month or the month's last day: 31 Jan + 1 = 28/29 Feb. */
    public static function batch_expires_at( string $earned_utc, int $months ): string {
        $earned = new \DateTimeImmutable( $earned_utc, new \DateTimeZone( 'UTC' ) );
        $first  = $earned->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
        $day    = min( (int) $earned->format( 'j' ), (int) $first->format( 't' ) );

        return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )->format( Dates::MYSQL_FORMAT );
    }

    /** The later of the two. Same-format UTC strings compare in date order. */
    public static function floor_at( string $expires_at, string $floor ): string {
        return strcmp( $expires_at, $floor ) >= 0 ? $expires_at : $floor;
    }

    /** Now + GRACE_DAYS, the earliest date a settings change may give anyone. */
    public static function grace_floor( string $now ): string {
        return self::inactivity_expires_at( $now, self::GRACE_DAYS );
    }
}
