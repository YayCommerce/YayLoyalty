<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Database\Installer;
use YayLoyalty\Engine\Storefront\AccountRewards;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/** Activation on a single site or a network, new-site setup and the WooCommerce boot hooks. */
class ActDeact {

    public static function install_woocommerce_admin_notice() {
        /* translators: %s: WooCommerce link */
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
            self::activate_network();

            return;
        }

        self::single_activate();
    }

    /**
     * Activates on every blog. Paged, because get_sites() defaults to 100 and would skip the rest silently.
     * try/finally so a throw cannot leave the process on the wrong blog.
     */
    private static function activate_network(): void {
        $paged    = 1;
        $per_page = 100;

        do {
            $blogs = get_sites(
                [
                    'number'        => $per_page,
                    'offset'        => ( $paged - 1 ) * $per_page,
                    'fields'        => 'ids',
                    'orderby'       => 'id',
                    'order'         => 'ASC',
                    'no_found_rows' => true,
                ]
            );

            foreach ( $blogs as $blog_id ) {
                switch_to_blog( (int) $blog_id );

                try {
                    self::single_activate();
                } finally {
                    restore_current_blog();
                }
            }

            $returned = count( $blogs );
            ++$paged;
        } while ( $returned === $per_page );
    }

    /**
     * Creates tables on a blog added after network activation.
     *
     * @param \WP_Site $new_site
     */
    public static function on_new_site( $new_site ): void {
        if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( ! is_plugin_active_for_network( YAYLOYALTY_BASE_NAME ) ) {
            return;
        }

        switch_to_blog( (int) $new_site->blog_id );

        try {
            self::single_activate();
        } finally {
            restore_current_blog();
        }
    }

    public static function deactivate() {
        // The next activation flushes the rewrite rules again.
        delete_option( AccountRewards::OPTION_REWRITE_VERSION );
    }

    protected static function single_activate() {
        Installer::install();

        SettingsHelper::get_settings();
    }
}
