<?php
/**
 * Plugin Name:       YayLoyalty — Points, Rewards, Referral & VIP for WooCommerce
 * Plugin URI:        https://yaycommerce.com/
 * Description:       Loyalty program plugin for WooCommerce: points, rewards, referral & VIP tiers.
 * Version:           1.0.0
 * Author:            YayCommerce
 * Author URI:        https://yaycommerce.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yayloyalty
 * Domain Path:       /languages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * WC requires at least: 10.0.0
 * WC tested up to: 10.7.0
 *
 * @package yaycommerce/yayloyalty
 */

namespace YayLoyalty;

defined( 'ABSPATH' ) || exit;

if ( function_exists( 'YayLoyalty\\plugin_init' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/Fallback.php';
    add_action(
        'admin_init',
        function () {
            deactivate_plugins( plugin_basename( __FILE__ ) );
        }
    );
}

if ( ! defined( 'YAYLOYALTY_FILE' ) ) {
    define( 'YAYLOYALTY_FILE', __FILE__ );
}

if ( ! defined( 'YAYLOYALTY_VERSION' ) ) {
    define( 'YAYLOYALTY_VERSION', '1.0.0' );
}

if ( ! defined( 'YAYLOYALTY_PLUGIN_URL' ) ) {
    define( 'YAYLOYALTY_PLUGIN_URL', plugin_dir_url( YAYLOYALTY_FILE ) );
}

if ( ! defined( 'YAYLOYALTY_PLUGIN_DIR' ) ) {
    define( 'YAYLOYALTY_PLUGIN_DIR', plugin_dir_path( YAYLOYALTY_FILE ) );
}

if ( ! defined( 'YAYLOYALTY_BASE_NAME' ) ) {
    define( 'YAYLOYALTY_BASE_NAME', plugin_basename( YAYLOYALTY_FILE ) );
}

if ( ! defined( 'YAYLOYALTY_MENU_ORDER' ) ) {
    define( 'YAYLOYALTY_MENU_ORDER', 3 );
}

// Plugin classes: YayLoyalty\* → includes/*
spl_autoload_register(
    function ( $class_name ) {
        $prefix   = __NAMESPACE__;
        $base_dir = __DIR__ . '/includes';

        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
            return;
        }

        $relative_class_name = substr( $class_name, $len );

        $file = $base_dir . str_replace( '\\', '/', $relative_class_name ) . '.php';

        if ( file_exists( $file ) ) {
            require $file;
        }
    }
);

// Scoped admin shell + root adapter (composer classmap: vendor-prefixed/src + adapter).
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/YayLoyaltyPluginAdapter.php';

// Boot the shared YayCommerce admin shell early so version election across
// coexisting Yay plugins happens before menus register.
add_action(
    'plugins_loaded',
    function () {
        \YayLoyaltyScoped\YayCommerce\AdminShell\AdminShell::boot();
        \YayLoyaltyScoped\YayCommerce\AdminShell\AdminShell::register_plugin(
            new \YayLoyaltyPluginAdapter()
        );
    },
    5
);

if ( ! function_exists( 'YayLoyalty\\plugin_init' ) ) {
    function plugin_init() {
        if ( ! function_exists( 'WC' ) ) {
            add_action( 'admin_notices', [ \YayLoyalty\Engine\ActDeact::class, 'install_woocommerce_admin_notice' ] );
            return;
        }

        add_action( 'before_woocommerce_init', [ \YayLoyalty\Engine\ActDeact::class, 'before_woocommerce_init' ] );

        YayLoyalty::initialize();
        I18n::load_plugin_textdomain();
    }
}//end if

if ( ! wp_installing() ) {
    add_action( 'plugins_loaded', 'YayLoyalty\\plugin_init' );
}

register_activation_hook( YAYLOYALTY_FILE, [ \YayLoyalty\Engine\ActDeact::class, 'activate' ] );
register_deactivation_hook( YAYLOYALTY_FILE, [ \YayLoyalty\Engine\ActDeact::class, 'deactivate' ] );
