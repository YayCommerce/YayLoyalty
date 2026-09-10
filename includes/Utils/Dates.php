<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * UTC date handling.
 *
 * Every DATETIME this plugin stores is UTC. Conversion to the site timezone
 * happens only at the edges: admin forms, emails, and REST responses meant for
 * display. Never call current_time() for a value that gets stored — a timestamp
 * that enters the database in site time surfaces months later as an
 * off-by-hours expiry.
 */
final class Dates {

    /** MySQL DATETIME format. */
    public const MYSQL_FORMAT = 'Y-m-d H:i:s';

    /**
     * Current instant as a UTC MySQL DATETIME string.
     */
    public static function now(): string {
        return gmdate( self::MYSQL_FORMAT );
    }

    /**
     * A UTC MySQL DATETIME offset by a number of seconds (negative to go back).
     */
    public static function offset( int $seconds, ?string $from_utc = null ): string {
        $base = null === $from_utc ? time() : self::to_timestamp( $from_utc );

        return gmdate( self::MYSQL_FORMAT, $base + $seconds );
    }

    /**
     * Unix timestamp for a UTC MySQL DATETIME string.
     */
    public static function to_timestamp( string $utc ): int {
        return (int) strtotime( $utc . ' UTC' );
    }

    /**
     * Convert a stored UTC DATETIME to the site timezone, for display only.
     */
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

    /**
     * True when $utc is a parseable MySQL DATETIME string.
     */
    public static function is_valid( ?string $utc ): bool {
        if ( null === $utc || '' === $utc ) {
            return false;
        }

        return false !== strtotime( $utc );
    }
}
