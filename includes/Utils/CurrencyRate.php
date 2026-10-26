<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The only place the plugin asks for an exchange rate. A rate is currency units per 1 base unit:
 * base to currency is amount × rate, currency to base is amount ÷ rate. Without a multi-currency
 * integration every rate is 1.0. Nothing here rounds; callers round once, to the target currency.
 */
final class CurrencyRate {

    /** The currency the current request charges in, which a multi-currency plugin may have switched. */
    public static function current(): string {
        return (string) get_woocommerce_currency();
    }

    /** Units of $currency per 1 base unit. Always 1.0 for the base currency and for an unusable answer. */
    public static function rate( string $currency, ?\WC_Order $order = null ): float {
        if ( (string) get_option( 'woocommerce_currency' ) === $currency ) {
            return 1.0;
        }

        /**
         * Units of $currency per 1 unit of the store's base currency. Default 1.0, which a multi-currency
         * integration replaces; a result that isn't numeric or isn't above 0 reads as 1.0.
         *
         * @param float          $rate     1.0.
         * @param string         $currency The currency to price in.
         * @param \WC_Order|null $order    The order the amount belongs to, whose saved rate should win; null outside an order.
         */
        $rate = apply_filters( 'yayloyalty_currency_rate', 1.0, $currency, $order );

        return is_numeric( $rate ) && (float) $rate > 0 ? (float) $rate : 1.0;
    }

    /** A base-currency amount in $currency, the current one by default. */
    public static function from_base( float $amount, ?string $currency = null ): float {
        return $amount * self::rate( null === $currency ? self::current() : $currency );
    }

    /** An amount in $currency converted to the base currency, at $order's saved rate when it has one. */
    public static function to_base( float $amount, string $currency, ?\WC_Order $order = null ): float {
        return $amount / self::rate( $currency, $order );
    }
}
