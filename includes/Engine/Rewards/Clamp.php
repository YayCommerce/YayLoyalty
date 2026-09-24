<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Models\Reward;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the redeemable amount is limited: how many of the points a
 * customer asked to spend may actually be spent on this cart, what discount
 * that buys, and which ceiling bit. Pure arithmetic over primitives — no
 * WordPress, no lookups — so every caller (cart quote, checkout validation,
 * the commit's re-check under the lock) gets the same answer from the same
 * code. No caller re-clamps.
 *
 * Ceilings in check order, the first that binds is reported: balance,
 * reward max (`max_points_per_order`), cart (the subtotal after other
 * coupons, tightened by `max_percent_of_subtotal`). The result is then
 * floored to the reward's `increment` (BOUND_STEP only when nothing else
 * bound) and zeroed below `min_points` (BOUND_REWARD_MIN unless a ceiling
 * bound, so "your balance is too low" wins over "below the minimum").
 * The seed's increment equals `points_per_currency_unit`, so by default a
 * step is one whole currency unit and the money value has no remainder.
 */
final class Clamp {

    public const BOUND_NONE       = '';
    public const BOUND_BALANCE    = 'balance';
    public const BOUND_REWARD_MAX = 'reward_max';
    public const BOUND_REWARD_MIN = 'reward_min';
    public const BOUND_CART       = 'cart';
    public const BOUND_STEP       = 'step';

    /**
     * Preconditions: $requested ≥ 0 (a negative is treated as 0); $balance is
     * the customer's spendable balance at the moment of asking; $cart_subtotal
     * is in $currency and already net of every other coupon, or null for a
     * claim with no cart (no cart ceiling then); $decimals is that currency's
     * (wc_get_price_decimals() at runtime).
     *
     * Postconditions: Result::allowed() whose get_points() is a multiple of
     * `increment`, ≤ every ceiling, and 0 when below `min_points`; get_value()
     * = points / points_per_currency_unit at $decimals dp; get_bound_by() names the
     * ceiling that reduced the request, or '' when the request stood. The
     * only denial is REASON_NO_RATE for a reward whose config has no usable
     * `points_per_currency_unit`.
     */
    public static function apply( Reward $reward, int $requested, int $balance, ?float $cart_subtotal, string $currency, int $decimals ): Result {
        $config = $reward->get_config();
        $rate   = (float) ( $config['points_per_currency_unit'] ?? 0 );

        if ( $rate <= 0 ) {
            return Result::denied( Result::REASON_NO_RATE );
        }

        $step = max( 1, (int) ( $config['increment'] ?? 1 ) );
        $min  = max( 0, (int) ( $config['min_points'] ?? 0 ) );

        $ceilings = [ self::BOUND_BALANCE => max( 0, $balance ) ];

        if ( isset( $config['max_points_per_order'] ) ) {
            $ceilings[ self::BOUND_REWARD_MAX ] = max( 0, (int) $config['max_points_per_order'] );
        }

        if ( null !== $cart_subtotal ) {
            $cart_points = self::points_in( $cart_subtotal, $rate );

            if ( isset( $config['max_percent_of_subtotal'] ) ) {
                $cart_points = min( $cart_points, self::points_in( $cart_subtotal * (float) $config['max_percent_of_subtotal'] / 100, $rate ) );
            }

            $ceilings[ self::BOUND_CART ] = $cart_points;
        }

        $applied  = max( 0, $requested );
        $bound_by = self::BOUND_NONE;

        foreach ( $ceilings as $name => $ceiling ) {
            if ( $ceiling < $applied ) {
                $applied  = $ceiling;
                $bound_by = $name;
            }
        }

        $stepped = $applied - ( $applied % $step );

        if ( $stepped !== $applied ) {
            $applied = $stepped;

            if ( self::BOUND_NONE === $bound_by ) {
                $bound_by = self::BOUND_STEP;
            }
        }

        if ( $applied < $min && $requested > 0 ) {
            $applied = 0;

            if ( in_array( $bound_by, [ self::BOUND_NONE, self::BOUND_STEP ], true ) ) {
                $bound_by = self::BOUND_REWARD_MIN;
            }
        }

        $value = PointsMath::points_to_currency( $applied, $rate, $decimals ) ?? 0.0;

        return Result::allowed( $reward, max( 0, $requested ), $applied, $value, $currency, $rate, $bound_by );
    }

    /** Whole points a money amount is worth; rounded before flooring so 0.29 × 100 is 29, not 28. */
    private static function points_in( float $amount, float $rate ): int {
        return max( 0, (int) floor( round( $amount * $rate, 6 ) ) );
    }
}
