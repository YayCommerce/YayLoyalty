<?php
namespace YayLoyalty\Integrations\YayCurrency;

defined( 'ABSPATH' ) || exit;

/**
 * The pure rate arithmetic behind the YayCurrency integration, free of WordPress so the unit suite can run it.
 * A rate is currency units per 1 base unit, YayCurrency's direction: base to currency is amount × rate.
 */
final class CurrencyRateMath {

    /**
     * The first usable rate: the one saved on the order, then YayCurrency's live one, else 1.0.
     * A candidate that isn't numeric or isn't above 0 is skipped.
     */
    public static function resolve( ?string $saved, ?float $live ): float {
        foreach ( [ $saved, $live ] as $candidate ) {
            if ( is_numeric( $candidate ) && (float) $candidate > 0 ) {
                return (float) $candidate;
            }
        }

        return 1.0;
    }

    /**
     * $rate with YayCurrency's exchange fee added when $include_fee, the way YayCurrencyHelper::get_rate_fee() adds it.
     * Production calls get_rate_fee() itself; this copy exists for the unit test.
     *
     * @param array{type?: string, value?: mixed} $fee
     */
    public static function fee_rate( float $rate, array $fee, bool $include_fee ): float {
        if ( ! $include_fee ) {
            return $rate;
        }

        $value = isset( $fee['value'] ) && is_numeric( $fee['value'] ) ? (float) $fee['value'] : 0.0;

        if ( isset( $fee['type'] ) && 'percentage' === $fee['type'] ) {
            return $rate + $rate * $value / 100;
        }

        return $rate + $value;
    }
}
