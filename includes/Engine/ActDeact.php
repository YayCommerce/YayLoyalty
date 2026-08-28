<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Activate and deactivate methods of the plugin.
 */
class ActDeact {

    public static function install_woocommerce_admin_notice() {
        /* translators: %s: Woocommerce link */
        echo '<div class="error"><p><strong>' . sprintf( esc_html__( 'YayLoyalty is enabled but not effective. It requires %s in order to work', 'yayloyalty' ), '<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">WooCommerce</a>' ) . '</strong></p></div>';
        return false;
    }

    public static function before_woocommerce_init() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', YAYLOYALTY_FILE, true );
        }
    }

    public static function activate( bool $network_wide ) {

        if ( ! function_exists( 'WC' ) ) {
            return;
        }

        if ( function_exists( 'is_multisite' ) && is_multisite() && $network_wide ) {
            /** @var array<\WP_Site> $blogs */
            $blogs = get_sites();

            foreach ( $blogs as $blog ) {
                switch_to_blog( (int) $blog->blog_id );
                self::single_activate();
                restore_current_blog();
            }

            return;
        }

        self::single_activate();
    }

    public static function deactivate() {
    }

    protected static function single_activate() {
        // Seed default settings on first activation.
        SettingsHelper::get_settings();
    }
}
