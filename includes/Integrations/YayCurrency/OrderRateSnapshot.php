<?php
namespace YayLoyalty\Integrations\YayCurrency;

use YayLoyalty\Utils\CurrencyRate;

defined( 'ABSPATH' ) || exit;

/**
 * The rate in force when a non-base order was placed, saved on the order so earning at completion
 * uses it even after the merchant changes YayCurrency's rates. Written once, never rewritten.
 */
final class OrderRateSnapshot {

    public const META_KEY = '_yayloyalty_currency_rate';

    /** Stages the meta only; both checkout hooks that call this save the order afterwards. */
    public static function save( \WC_Order $order ): void {
        $currency = (string) $order->get_currency();

        if ( (string) get_option( 'woocommerce_currency' ) === $currency || null !== self::read( $order ) ) {
            return;
        }

        $order->update_meta_data( self::META_KEY, wc_format_decimal( CurrencyRate::rate( $currency ), 10 ) );
    }

    /** The saved rate as a decimal string, or null when the order has none. */
    public static function read( \WC_Order $order ): ?string {
        $saved = (string) $order->get_meta( self::META_KEY );

        return '' === $saved ? null : $saved;
    }
}
