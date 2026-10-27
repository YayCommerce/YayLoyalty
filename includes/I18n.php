<?php

namespace YayLoyalty;

defined( 'ABSPATH' ) || exit;
/** Loads the plugin's translations. */
class I18n {

    public static function load_plugin_textdomain() {
        if ( function_exists( 'determine_locale' ) ) {
            $locale = determine_locale();
        } else {
            $locale = is_admin() ? get_user_locale() : get_locale();
        }
        unload_textdomain( 'yayloyalty' );
        load_textdomain( 'yayloyalty', YAYLOYALTY_PLUGIN_DIR . '/languages/yayloyalty-' . $locale . '.mo' );
        load_plugin_textdomain( 'yayloyalty', false, YAYLOYALTY_PLUGIN_DIR . '/languages/' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Pro ships outside WordPress.org, so the plugin loads its own translations.
    }
}
