<?php

namespace YayLoyalty;

defined( 'ABSPATH' ) || exit;
/** Loads the plugin's translations. */
class I18n {

    public static function load_translations() {
        if ( function_exists( 'determine_locale' ) ) {
            $locale = determine_locale();
        } else {
            $locale = is_admin() ? get_user_locale() : get_locale();
        }
        unload_textdomain( 'yayloyalty' );
        load_textdomain( 'yayloyalty', YAYLOYALTY_PLUGIN_DIR . '/languages/yayloyalty-' . $locale . '.mo' );
        // unload_textdomain() stops just-in-time loading, so the translate.wordpress.org file is loaded here too.
        load_textdomain( 'yayloyalty', WP_LANG_DIR . '/plugins/yayloyalty-' . $locale . '.mo' );
    }
}
