<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * UTC date handling. Every stored DATETIME is UTC; convert to the site zone
 * only for display. Never store current_time(): a site-time value surfaces
 * months later as an off-by-hours expiry.
 */
final class Dates {

    public const MYSQL_FORMAT = 'Y-m-d H:i:s';

    /** MySQL's zero DATETIME, which WordPress stores for "no date"; strtotime() parses it, so it is checked by name. */
    public const ZERO = '0000-00-00 00:00:00';

    /** Now as a UTC MySQL DATETIME. */
    public static function now(): string {
        return gmdate( self::MYSQL_FORMAT );
    }

    /** A UTC MySQL DATETIME offset by seconds (negative to go back). */
    public static function offset( int $seconds, ?string $from_utc = null ): string {
        $base = null === $from_utc ? time() : self::to_timestamp( $from_utc );

        return gmdate( self::MYSQL_FORMAT, $base + $seconds );
    }

    /** A moment in any zone as a UTC MySQL DATETIME, the form every stored and queried bound takes. */
    public static function to_utc( \DateTimeImmutable $at ): string {
        return $at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::MYSQL_FORMAT );
    }

    public static function to_timestamp( string $utc ): int {
        return (int) strtotime( $utc . ' UTC' );
    }

    /** For display only. */
    public static function to_site_tz( string $utc, ?string $format = null ): string {
        if ( null === $format || '' === $format ) {
            $format = self::MYSQL_FORMAT;
        }

        $date = date_create( $utc, timezone_open( 'UTC' ) );

        if ( false === $date ) {
            return $utc;
        }

        $date->setTimezone( wp_timezone() );

        return $date->format( $format );
    }

    public static function is_valid( ?string $utc ): bool {
        if ( null === $utc || '' === $utc || self::ZERO === $utc ) {
            return false;
        }

        return false !== strtotime( $utc );
    }
}
