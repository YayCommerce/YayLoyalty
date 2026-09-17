<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Database\Installer;
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
            self::activate_network();

            return;
        }

        self::single_activate();
    }

    /**
     * Activate on every blog in the network.
     *
     * get_sites() defaults to a limit of 100, so a naive call silently skips
     * every blog past the first hundred — they would run without tables until
     * something noticed. Paginate explicitly.
     *
     * switch_to_blog() is wrapped in try/finally: a throw inside
     * single_activate() would otherwise leave the process pointed at the wrong
     * blog for the remainder of the request.
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
     * Create tables on a blog added after the plugin was network-activated.
     *
     * Hooked to wp_initialize_site; without it, new blogs never get tables.
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
    }

    protected static function single_activate() {
        // Tables, DB version stamp, capabilities, built-in campaigns/reward and
        // one-time starter customers — see Installer::install().
        Installer::install();

        // Warm the settings read (no write — defaults merge on every read).
        SettingsHelper::get_settings();
    }
}
