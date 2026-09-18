<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The points arithmetic every layer shares, mirrored in
 * apps/admin/src/lib/points-math/. A zero denominator yields null, never 0:
 * the SPA renders "—" for null. Every public method has a vector in
 * tests/fixtures/points-math-vectors.json that both suites iterate; PHP is
 * the source of truth.
 */
final class PointsMath {

    public const ROUND_DOWN    = 'down';
    public const ROUND_NEAREST = 'nearest';
    public const ROUND_UP      = 'up';

    /** Breakdown marker for flat awards, which never round; not a config value. */
    public const ROUND_NONE = 'none';

    /**
     * Raw points to a whole number, once at order level, never per line: the
     * total must not depend on how the cart was split. An unknown mode rounds down.
     */
    public static function round_points( float $raw, string $mode ): int {
        switch ( $mode ) {
            case self::ROUND_UP:
                return (int) ceil( $raw );

            case self::ROUND_NEAREST:
                return (int) round( $raw, 0, PHP_ROUND_HALF_UP );

            case self::ROUND_DOWN:
            default:
                return (int) floor( $raw );
        }
    }

    /**
     * Percentage change from $previous to $current; null with no previous.
     * Denominator is |previous| so a negative base cannot report a rise as a fall.
     *
     * @param int|float $current
     * @param int|float $previous
     */
    public static function pct_delta( $current, $previous, int $decimals = 1 ): ?float {
        $previous = (float) $previous;

        if ( 0.0 === $previous ) {
            return null;
        }

        return round( ( (float) $current - $previous ) / abs( $previous ) * 100, $decimals );
    }

    /** $part as a percentage of $whole, 1 dp; null when $whole is zero. */
    public static function pct_of( int $part, int $whole ): ?float {
        if ( 0 === $whole ) {
            return null;
        }

        return round( $part / $whole * 100, 1 );
    }

    /**
     * Points in store currency, 2 dp; null when no usable rate exists.
     *
     * @param int|float $points_per_currency_unit
     */
    public static function points_to_currency( int $points, $points_per_currency_unit ): ?float {
        if ( $points_per_currency_unit <= 0 ) {
            return null;
        }

        return round( $points / $points_per_currency_unit, 2 );
    }
}
