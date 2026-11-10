<?php

defined( 'ABSPATH' ) || exit;

/**
 * Registers YayLoyalty's menu and settings page with the shared AdminShell.
 * Un-namespaced and excluded from PHP-Scoper (see scoper.inc.php), so runtime
 * checks like class_exists() run against the real WordPress and WooCommerce symbols.
 */
class YayLoyaltyPluginAdapter implements \YayLoyaltyScoped\YayCommerce\AdminShell\Contracts\PluginMenuAdapter {

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
     * AdminShell also applies this capability to the licence REST routes and LicenseHandler,
     * so a viewer can reach those. The plugin's own routes each check their own capability.
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
		return ''; }

    /**
     * Shows AdminShell's "WooCommerce required" screen in place of the settings page.
     * Not in the interface: AdminShell looks for it with method_exists().
     */
    public function needs_woocommerce_screen(): bool {
        return ! class_exists( 'WooCommerce' );
    }

    // --- License methods ---
	public function get_plugin_slug(): string {
		return 'yayloyalty'; }
    public function get_plugin_name(): string {
        /**
         * Filters the product name the licence screens show. This adapter ships in both
         * builds, so the edition suffix cannot live here: the paid build appends its own
         * when it boots.
         *
         * @param string $name Base product name.
         */
        return (string) apply_filters( 'yayloyalty_plugin_name', 'YayLoyalty' );
    }
	public function get_plugin_version(): string {
		return defined( 'YAYLOYALTY_VERSION' ) ? YAYLOYALTY_VERSION : '0.0.0'; }
	public function get_plugin_file(): string {
		return YAYLOYALTY_PLUGIN_DIR . 'yayloyalty.php'; }
	public function get_item_id(): int {
		return 0; }
	public function get_store_url(): string {
		return 'https://yaycommerce.com/'; }
	public function get_store_link(): string {
		return 'https://yaycommerce.com/yayloyalty/'; }

	/**
	 * Whether Pro features may run: a license key was activated on this site.
	 * An expired license only stops updates and support (admin-shell 2.9+).
	 */
	public static function is_licensed(): bool {
		if ( class_exists( '\YayLoyaltyScoped\YayCommerce\AdminShell\License\LicenseState' ) ) {
			return \YayLoyaltyScoped\YayCommerce\AdminShell\License\LicenseState::allows_features( 'yayloyalty' );
		}
		return ! empty( get_option( 'yayloyalty_license_key', '' ) ) && ! empty( get_option( 'yayloyalty_license_info', [] ) );
	}
}
