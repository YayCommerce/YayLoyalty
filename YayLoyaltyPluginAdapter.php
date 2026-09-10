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
            echo '<div id="yayloyalty"></div>';
        };
    }

    public function get_settings_page_position(): ?int {
        return YAYLOYALTY_MENU_ORDER;
    }

    public function get_capability(): string {
        return 'manage_woocommerce';
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
