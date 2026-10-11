<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Models\Reward;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * The one place the redeemable amount is limited: how many requested points this cart may take,
 * the discount they buy, and which ceiling bound. Pure arithmetic, so the cart quote, checkout
 * validation and the commit's re-check all get the same answer. No caller re-clamps.
 * A ceiling outranks the minimum, so "your balance is too low" wins over "below the minimum".
 */
final class Clamp {

    public const BOUND_NONE       = '';
    public const BOUND_BALANCE    = 'balance';
    public const BOUND_REWARD_MAX = 'reward_max';
    public const BOUND_REWARD_MIN = 'reward_min';
    public const BOUND_CART       = 'cart';
    public const BOUND_STEP       = 'step';

    /**
     * $cart_subtotal is in $currency and already net of every other coupon, or null for a claim with no cart.
     * Ceilings are checked in order (balance, reward max, cart) and the first that binds is reported.
     * The only denial is REASON_NO_RATE, for a reward with no usable `points_per_currency_unit`.
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

    /** Whole points a money amount is worth, rounded down. */
    private static function points_in( float $amount, float $rate ): int {
        return max( 0, PointsMath::round_points( $amount * $rate, PointsMath::ROUND_DOWN ) );
    }
}
