<?php
/**
 * Plugin adapter for YayLoyalty Lite.
 * Un-namespaced and excluded from PHP-Scoper (see scoper.inc.php) so runtime
 * checks like class_exists() run against real WordPress/WooCommerce symbols.
 */

defined( 'ABSPATH' ) || exit;

class YayLoyaltyPluginAdapter implements \YayLoyaltyScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter {

    // --- Menu methods ---

    public function get_menu_title(): string {
        return 'YayLoyalty';
    }

    public function get_page_title(): string {
        return 'YayLoyalty — Points, Rewards, Referral & VIP for WooCommerce';
    }

    public function get_menu_slug(): string {
        return 'yayloyalty';
    }

    public function get_settings_page_callback(): ?callable {
        return function () {
            // The menu capability hides the link; it does not stop a pasted URL.
            if ( ! current_user_can( \YayLoyalty\Access\Capabilities::VIEW ) ) {
                wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'yayloyalty' ), 403 );
            }

            echo '<div id="yayloyalty"></div>';
        };
    }

    public function get_settings_page_position(): ?int {
        return YAYLOYALTY_MENU_ORDER;
    }

    /**
     * Menu capability. AdminShell also applies it to the licence REST routes
     * and LicenseHandler, so a viewer can reach those; the plugin's own routes
     * carry their own capability each (see Capabilities).
     */
    public function get_capability(): string {
        return \YayLoyalty\Access\Capabilities::VIEW;
    }

    public function get_plugin_basename(): string {
        return YAYLOYALTY_BASE_NAME;
    }

    public function get_settings_label(): string {
        return 'Settings';
    }

    public function get_docs_url(): string {
        return 'https://docs.yaycommerce.com/';
    }

    public function get_pro_url(): string {
        return 'https://yaycommerce.com/';
    }

    // --- Optional capability methods (detected by AdminShell via method_exists) ---

    /**
     * Render AdminShell's shared "WooCommerce required" screen in place of the
     * settings page when WooCommerce is inactive.
     */
    public function needs_woocommerce_screen(): bool {
        return ! class_exists( 'WooCommerce' );
    }
}
