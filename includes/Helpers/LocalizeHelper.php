<?php
namespace YayLoyalty\Helpers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\BaseRestController;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/** Localized data for the admin SPA: VAR_ADMIN → window.yayLoyaltyAdmin, VAR_META → window.yayLoyaltyMeta. */
class LocalizeHelper {
    public const VAR_ADMIN = 'yayLoyaltyAdmin';
    public const VAR_META  = 'yayLoyaltyMeta';

    public static function get_admin_data() {
        $admin_data = array_merge(
            [
                'settings' => SettingsHelper::get_settings(),
                'design'   => DesignHelper::get_design(),
            ],
            Capabilities::payload()
        );

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
                // Slug => translated display name, e.g. 'shop_manager' => 'Shop Manager'.
                // Lets role pickers (Design visibility, etc.) offer real roles instead of free text.
                'roles'        => wp_roles()->get_names(),
            ],
            // No pro flag: the SPA is gated at build time (IS_PRO_BUILD), and one zip carries both.
            'loyaltyMeta' => [
                'pluginUrl' => YAYLOYALTY_PLUGIN_URL,
                'assetsUrl' => YAYLOYALTY_PLUGIN_URL . 'assets',
                'version'   => YAYLOYALTY_VERSION,
                'reviewed'  => (bool) get_option( SettingsHelper::OPTION_REVIEWED, false ),
            ],
            'wcMeta'      => [
                'currency_data'    => [
                    'currency'     => $currency,
                    'symbol'       => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $currency ) ) : '$',
                    'position'     => get_option( 'woocommerce_currency_pos', 'left' ),
                    'thousand_sep' => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
                    'decimal_sep'  => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
                    'num_decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
                ],
                // id => admin title, code => name. The campaign condition pickers offer these
                // instead of free text; CampaignConditionSet validates currencies against the same list.
                'payment_gateways' => self::payment_gateways(),
                'countries'        => self::decoded_names( function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [] ),
                'currencies'       => self::decoded_names( function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : [] ),
                // True when WC isn't loaded, so the Rewards notice never raises a false alarm.
                'coupons_enabled'  => function_exists( 'wc_coupons_enabled' ) ? wc_coupons_enabled() : true,
            ],
        ];

        return apply_filters( 'yayloyalty_localize_meta', $meta );
    }

    /**
     * Every registered gateway, enabled or not, as id => admin title; a paused gateway can still
     * be named by a campaign.
     *
     * @return array<string, string>
     */
    private static function payment_gateways(): array {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
            return [];
        }

        $gateways = [];

        foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
            $gateways[ (string) $gateway->id ] = Text::plain( (string) $gateway->get_method_title() );
        }

        return $gateways;
    }

    /**
     * @param array<string, string> $names
     * @return array<string, string>
     */
    private static function decoded_names( array $names ): array {
        return array_map( static fn( $name ) => Text::plain( (string) $name ), $names );
    }
}
