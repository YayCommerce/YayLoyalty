<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * UTC date handling. Every stored DATETIME is UTC, converted to the site zone only for display.
 * Never store current_time(): a site-time value surfaces months later as an off-by-hours expiry.
 */
final class Dates {

    public const MYSQL_FORMAT = 'Y-m-d H:i:s';

    /** MySQL's zero DATETIME, which WordPress stores for "no date". strtotime() parses it, so it is checked by name. */
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

    /**
     * Midnight, in the site timezone, of the site-calendar day holding a UTC moment.
     * Wall-clock midnight, so a DST day stays a calendar day rather than a fixed 24 hours.
     *
     * @return \DateTimeImmutable|null Null when $utc is empty or unparsable.
     */
    public static function site_day_start( ?string $utc ): ?\DateTimeImmutable {
        if ( null === $utc || '' === $utc ) {
            return null;
        }

        $date = date_create_immutable( $utc, new \DateTimeZone( 'UTC' ) );

        return false === $date ? null : $date->setTimezone( wp_timezone() )->setTime( 0, 0 );
    }

    /**
     * UTC bound for site-timezone midnight of a `Y-m-d` calendar day, shifted
     * by $modify (`'+1 day'` for an exclusive end). Wall-clock midnight, as
     * site_day_start(). Null when $ymd is not a valid `Y-m-d`.
     */
    public static function site_day_to_utc( string $ymd, string $modify = '' ): ?string {
        $start = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );

        if ( false === $start ) {
            return null;
        }

        return self::to_utc( '' === $modify ? $start : $start->modify( $modify ) );
    }

    /**
     * A UTC DATETIME in the site's timezone, for display only. It goes through wp_date() so month and day
     * names follow the site language. An unreadable value comes back unchanged.
     */
    public static function to_site_tz( string $utc, ?string $format = null ): string {
        if ( null === $format || '' === $format ) {
            $format = self::MYSQL_FORMAT;
        }

        $timestamp = self::to_timestamp( $utc );
        $formatted = 0 === $timestamp ? false : wp_date( $format, $timestamp );

        return false === $formatted ? $utc : $formatted;
    }

    /** A UTC DATETIME as a date in the site's date format, for display. */
    public static function to_site_date( string $utc ): string {
        return self::to_site_tz( $utc, (string) get_option( 'date_format' ) );
    }

    public static function is_valid( ?string $utc ): bool {
        if ( null === $utc || '' === $utc || self::ZERO === $utc ) {
            return false;
        }

        return false !== strtotime( $utc );
    }
}
