<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The points arithmetic every layer shares: engine, dashboard, and — through
 * a same-named mirror in `apps/admin/src/lib/points-math/` — the admin SPA.
 *
 * Kept pure so every rounding and null rule has a unit test. Rule shared by
 * every ratio here: a zero denominator yields null, never 0 — the SPA renders
 * "—" / "no previous period" for null and would otherwise show a misleading 0%.
 *
 * Every public method has a key in `tests/fixtures/points-math-vectors.json`
 * and a camelCase export on the TS side; both test suites iterate that file,
 * so adding a rule on one side without the other fails that side's tests.
 * PHP is the source of truth for expected values.
 */
final class PointsMath {

    public const ROUND_DOWN    = 'down';
    public const ROUND_NEAREST = 'nearest';
    public const ROUND_UP      = 'up';

    /** Breakdown marker for flat awards, which never round. Not a config value. */
    public const ROUND_NONE = 'none';

    /**
     * Raw points to a whole number, ONCE, at order level. Half away from zero.
     *
     * Never per line item: rounding each line down would lose up to a point per
     * line and make the total depend on how the cart was split. An unknown
     * mode rounds down — the conservative direction for a liability.
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
     * Percentage change from $previous to $current; null when there is no
     * previous figure to compare against.
     *
     * The denominator is |previous| so the sign always follows the direction
     * of travel: a signed base (liability's previous period is
     * net_balance - net_delta, which can dip below zero) would otherwise
     * report a rise as a fall.
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

    /**
     * $part as a percentage of $whole, 1 dp; null when $whole is zero.
     */
    public static function pct_of( int $part, int $whole ): ?float {
        if ( 0 === $whole ) {
            return null;
        }

        return round( $part / $whole * 100, 1 );
    }

    /**
     * Points expressed in store currency at $points_per_currency_unit, 2 dp;
     * null when no usable conversion rate exists.
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
