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
 * Only points that buy whole smallest currency units apply, so the debit always equals the discount
 * times the rate; points_for() is the inverse the commit uses to turn an order's discount back into points.
 * The reward's points_per_currency_unit is set in the base currency. $currency_rate (units of the charged
 * currency per 1 base unit, CurrencyRate's direction) turns it into points per charged unit, so subtotals,
 * values and the rate snapshot are all in the charged currency.
 */
final class Clamp {

    public const BOUND_NONE       = '';
    public const BOUND_BALANCE    = 'balance';
    public const BOUND_REWARD_MAX = 'reward_max';
    public const BOUND_REWARD_MIN = 'reward_min';
    public const BOUND_CART       = 'cart';
    public const BOUND_STEP       = 'step';

    /**
     * $discountable_amount is in $currency and already net of every other coupon, or null for a claim with no cart.
     * The result's value and rate snapshot are in $currency too.
     * Ceilings are checked in order (balance, reward max, cart) and the first that binds is reported.
     * The only denial is REASON_NO_RATE, for a reward with no usable `points_per_currency_unit`.
     *
     * @param float $currency_rate Units of $currency per 1 base unit; 1.0 in the base currency.
     */
    public static function apply( Reward $reward, int $requested, int $balance, ?float $discountable_amount, string $currency, int $decimals, float $currency_rate = 1.0 ): Result {
        $config = $reward->get_config();
        $rate   = self::effective_rate( $reward, $currency_rate );

        if ( $rate <= 0 ) {
            return Result::denied( Result::REASON_NO_RATE );
        }

        $step = self::step_of( $reward );
        $min  = self::min_of( $reward );

        $ceilings = [ self::BOUND_BALANCE => max( 0, $balance ) ];

        if ( isset( $config['max_points_per_order'] ) ) {
            $ceilings[ self::BOUND_REWARD_MAX ] = max( 0, (int) $config['max_points_per_order'] );
        }

        if ( null !== $discountable_amount ) {
            $cart_points = self::points_in( $discountable_amount, $rate );

            if ( isset( $config['max_percent_of_discountable'] ) ) {
                $cart_points = min( $cart_points, self::points_in( $discountable_amount * (float) $config['max_percent_of_discountable'] / 100, $rate ) );
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

        // Down to the step, then to the points that buy whole smallest currency units.
        $stepped = $applied - ( $applied % $step );
        $value   = self::value_of( $stepped, $rate, $decimals );
        $whole   = min( $stepped, self::points_for( $value, $reward, $currency_rate ) );

        if ( $whole !== $applied ) {
            $applied = $whole;

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

        return Result::allowed( $reward, max( 0, $requested ), $applied, $applied > 0 ? $value : 0.0, $currency, $rate, $bound_by );
    }

    /**
     * The points a money amount costs: the smallest multiple of the step that pays for it. 0 without a rate or an amount.
     *
     * @param float $value         In the currency $currency_rate prices.
     * @param float $currency_rate Units of that currency per 1 base unit; 1.0 in the base currency.
     */
    public static function points_for( float $value, Reward $reward, float $currency_rate = 1.0 ): int {
        $rate = self::effective_rate( $reward, $currency_rate );

        if ( $rate <= 0 || $value <= 0 ) {
            return 0;
        }

        $step   = self::step_of( $reward );
        $points = (int) ceil( round( $value * $rate, 6 ) );

        return (int) ( ceil( $points / $step ) * $step );
    }

    /**
     * The step the card offers: the smallest multiple of step_of() whose points buy whole smallest currency
     * units, so apply() keeps it as asked. At 25,000 points per cent that is 25,000, not a step of 100 that buys
     * nothing. Falls back to step_of() when no multiple near one unit's worth is kept (an irregular rate).
     *
     * @param float $currency_rate Units of the charged currency per 1 base unit; 1.0 in the base currency.
     */
    public static function effective_step( Reward $reward, int $decimals, float $currency_rate = 1.0 ): int {
        $step = self::step_of( $reward );
        $rate = self::effective_rate( $reward, $currency_rate );

        if ( $rate <= 0 ) {
            return $step;
        }

        // Fewer points than one smallest unit's worth buy nothing, so start there.
        $first = max( 1, (int) ceil( round( $rate / pow( 10, max( 0, $decimals ) ) / $step, 6 ) ) );

        for ( $k = $first; $k <= $first + 100; $k++ ) {
            $points = $k * $step;
            $value  = self::value_of( $points, $rate, $decimals );

            if ( $value > 0 && self::points_for( $value, $reward, $currency_rate ) >= $points ) {
                return $points;
            }
        }

        return $step;
    }

    /** Points per unit of the charged currency: the base-currency rate over $currency_rate, which reads as 1.0 when it isn't above 0. */
    private static function effective_rate( Reward $reward, float $currency_rate ): float {
        $rate = (float) ( $reward->get_config()['points_per_currency_unit'] ?? 0 );

        return $rate / ( $currency_rate > 0 ? $currency_rate : 1.0 );
    }

    /** The reward's step: points are spent in multiples of it. At least 1. */
    public static function step_of( Reward $reward ): int {
        return max( 1, (int) ( $reward->get_config()['increment'] ?? 1 ) );
    }

    /**
     * Whether a tick asks for the most the cart allows (`redemption_mode` auto_max) rather than a typed amount.
     * apply() never reads it: RedemptionService::quote() turns it into the request, so the commit re-checks the line.
     */
    public static function is_auto_max( Reward $reward ): bool {
        return Reward::REDEMPTION_MODE_AUTO_MAX === ( $reward->get_config()['redemption_mode'] ?? Reward::REDEMPTION_MODE_AUTO_MAX );
    }

    /** The fewest points one order may take. Not rounded to the step. */
    public static function min_of( Reward $reward ): int {
        return max( 0, (int) ( $reward->get_config()['min_points'] ?? 0 ) );
    }

    /** What points buy, floored to the currency's smallest unit; snapped before flooring, as points_in() is. */
    private static function value_of( int $points, float $rate, int $decimals ): float {
        return PointsMath::floor_to_decimals( $points / $rate, $decimals );
    }

    /** Whole points a money amount is worth, rounded down. */
    private static function points_in( float $amount, float $rate ): int {
        return max( 0, PointsMath::round_points( $amount * $rate, PointsMath::ROUND_DOWN ) );
    }
}
