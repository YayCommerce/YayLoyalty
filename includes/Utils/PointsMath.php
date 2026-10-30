<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The points arithmetic every layer shares. PHP is the source of truth; apps/admin/src/lib/points-math/ copies
 * SNAP_DECIMALS, round_points(), pct_delta() and pct_of(), the parts the admin SPA previews, and
 * ratePair() in apps/admin/src/lib/helpers/points.helper.ts copies whole_pair().
 * A zero denominator yields null, never 0, and the SPA renders "—" for null.
 */
final class PointsMath {

    public const ROUND_DOWN    = 'down';
    public const ROUND_NEAREST = 'nearest';
    public const ROUND_UP      = 'up';

    /** Breakdown marker for flat awards, which never round. Not a config value. */
    public const ROUND_NONE = 'none';

    /** Far finer than any money × rate product, far coarser than float error (~1e-14). */
    private const SNAP_DECIMALS = 9;

    /**
     * Raw points to a whole number, once at order level, never per line: the
     * total must not depend on how the cart was split. An unknown mode rounds down.
     * Snaps first, so float error (0.57 × 100 = 56.999…) never crosses a whole number.
     */
    public static function round_points( float $raw, string $mode ): int {
        $raw = self::snap( $raw );

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
     * The pair a rate reads as in whole points, exactly: the smallest spend from 1 to 100 whose points are whole
     * (1.5 → 3 per 2, 0.75 → 3 per 4), else one point and the whole currency amount it takes (0.00004 → 1 per
     * 25,000). Null when neither is exact.
     *
     * @param float $rate Points per 1 unit of currency.
     * @return array{0: int, 1: float}|null Points, then the spend they are paired with.
     */
    public static function whole_pair( float $rate, int $decimals ): ?array {
        if ( $rate <= 0 ) {
            return null;
        }

        for ( $spend = 1; $spend <= 100; $spend++ ) {
            $points = self::snap( $rate * $spend );

            if ( $points >= 1 && floor( $points ) === $points ) {
                return [ (int) $points, (float) $spend ];
            }
        }

        $scale = pow( 10, max( 0, $decimals ) );
        $spend = round( $scale / $rate ) / $scale;

        return $spend > 0 && 1.0 === self::snap( $spend * $rate ) ? [ 1, $spend ] : null;
    }

    /**
     * The pair a rate is shown as, always in whole points: whole_pair(), or else what the first round spend (1, 10,
     * 100…) holding 100 or more points comes to under $mode, which is exactly what an order of that size earns:
     * 25.387 per $1 rounded down reads 253 per $10.
     *
     * @param float $rate Points per 1 unit of currency.
     * @return array{0: int, 1: float} Points, then the spend they are paired with; [ 0, 1.0 ] without a rate.
     */
    public static function rate_pair( float $rate, int $decimals, string $mode ): array {
        $pair = self::whole_pair( $rate, $decimals );

        if ( null !== $pair ) {
            return $pair;
        }

        if ( $rate <= 0 ) {
            return [ 0, 1.0 ];
        }

        // 100 points or more keeps the shown rate within 1% of the true one.
        for ( $spend = 1.0; $spend < 1e9; $spend *= 10 ) {
            $points = self::round_points( $rate * $spend, $mode );

            if ( $points >= 100 ) {
                return [ $points, $spend ];
            }
        }

        return [ self::round_points( $rate * $spend, $mode ), $spend ];
    }

    /** $value with float error removed (0.57 × 100 = 56.999… becomes 57), before a comparison or a rounding. */
    public static function snap( float $value ): float {
        return round( $value, self::SNAP_DECIMALS );
    }

    /**
     * Money floored to $decimals, so a discount never exceeds what pays for it. Snapped first, at the
     * currency's scale, so 250000 × 0.00004 = 9.999… still floors to 10.
     */
    public static function floor_to_decimals( float $amount, int $decimals ): float {
        $scale = pow( 10, max( 0, $decimals ) );

        return floor( round( $amount * $scale, 6 ) ) / $scale;
    }

    /**
     * Percentage change from $previous to $current, or null with no previous.
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

    /** $part as a percentage of $whole to 1 dp, or null when $whole is zero. */
    public static function pct_of( int $part, int $whole ): ?float {
        if ( 0 === $whole ) {
            return null;
        }

        return round( $part / $whole * 100, 1 );
    }

    /**
     * $value to $digits significant digits, for displaying a converted rate only, never for math:
     * 0.00004 stays 0.00004, and 1.0989010989 becomes 1.099.
     */
    public static function round_significant( float $value, int $digits = 4 ): float {
        if ( 0.0 === $value ) {
            return 0.0;
        }

        return round( $value, $digits - 1 - (int) floor( log10( abs( $value ) ) ) );
    }

    /**
     * Points in store currency, rounded to that currency's own decimals, or null when no usable rate exists.
     * The caller passes the decimals (wc_get_price_decimals() at runtime), so this stays free of WordPress.
     * A fixed 2 would drop a digit from a 3-decimal currency (KWD) and round a 0-decimal one (VND) twice.
     *
     * @param int|float $points_per_currency_unit
     */
    public static function points_to_currency( int $points, $points_per_currency_unit, int $decimals ): ?float {
        if ( $points_per_currency_unit <= 0 ) {
            return null;
        }

        return round( $points / $points_per_currency_unit, $decimals );
    }
}
