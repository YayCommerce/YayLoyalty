<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * ISO code checks: the format, plus membership in WooCommerce's list when it is loaded (not under plain PHPUnit).
 */
final class IsoCodes {

    /** An ISO 4217 currency code. */
    public static function is_currency( string $code ): bool {
        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
            return false;
        }

        if ( function_exists( 'get_woocommerce_currencies' ) ) {
            return array_key_exists( $code, get_woocommerce_currencies() );
        }

        return true;
    }

    /** An ISO 3166-1 alpha-2 country code. */
    public static function is_country( string $code ): bool {
        if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
            return false;
        }

        if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_callable( [ WC()->countries, 'get_countries' ] ) ) {
            return array_key_exists( $code, WC()->countries->get_countries() );
        }

        return true;
    }
}
