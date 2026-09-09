<?php
namespace YayLoyalty\Helpers;

use YayLoyalty\Controllers\BaseRestController;

defined( 'ABSPATH' ) || exit;

/**
 * Localized data for the admin React app.
 * VAR_ADMIN → window.yayLoyaltyAdmin, VAR_META → window.yayLoyaltyMeta.
 */
class LocalizeHelper {
    public const VAR_ADMIN = 'yayLoyaltyAdmin';
    public const VAR_META  = 'yayLoyaltyMeta';

    public static function get_admin_data() {
        $admin_data = [
            'settings' => SettingsHelper::get_settings(),
        ];

        return apply_filters( 'yayloyalty_localize_admin_data', $admin_data );
    }

    public static function get_meta() {
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

        $meta = [
            'wpMeta'      => [
                'siteUrl'      => esc_url_raw( site_url() ),
                'adminUrl'     => esc_url_raw( admin_url() ),
                'ajaxUrl'      => esc_url_raw( admin_url( 'admin-ajax.php' ) ),

                'restRoot'     => esc_url_raw( rest_url() ),
                'restBase'     => BaseRestController::REST_NAMESPACE,
                'restNonce'    => wp_create_nonce( 'wp_rest' ),
                'isBlockTheme' => wp_is_block_theme(),
            ],
            /*
             * No premium flag is sent to the browser. The SPA is gated at build
             * time by the __IS_PREMIUM__ Vite define: one zip carries both the
             * PHP and the JS bundle, so build type is never ambiguous and there
             * is nothing for a runtime flag to disambiguate.
             *
             * YayLoyalty::is_premium() remains for server-side decisions only.
             */
            'loyaltyMeta' => [
                'pluginUrl' => YAYLOYALTY_PLUGIN_URL,
                'assetsUrl' => YAYLOYALTY_PLUGIN_URL . 'assets',
                'version'   => YAYLOYALTY_VERSION,
                'reviewed'  => (bool) get_option( SettingsHelper::OPTION_REVIEWED, false ),
            ],
            'wcMeta'      => [
                'currency_data' => [
                    'currency'     => $currency,
                    'symbol'       => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $currency ) ) : '$',
                    'position'     => get_option( 'woocommerce_currency_pos', 'left' ),
                    'thousand_sep' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
                    'decimal_sep'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
                    'num_decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
                ],
            ],
        ];

        return apply_filters( 'yayloyalty_localize_meta', $meta );
    }
}
