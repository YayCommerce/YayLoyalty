<?php
namespace YayLoyalty\Engine\Admin;

use YayLoyalty\Utils\SingletonTrait;
use YayLoyalty\Helpers\LocalizeHelper;
use YayLoyalty\Engine\Register\ScriptName;

defined( 'ABSPATH' ) || exit;
/**
 * Settings Page
 */
class Settings {
    use SingletonTrait;

    protected function __construct() {

        add_filter( 'admin_body_class', [ $this, 'admin_body_class' ] );

        add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
    }

    public function admin_body_class( string $classes ): string {
        if ( strpos( $classes, 'yay-ui' ) === false ) {
            $classes .= ' yay-ui';
        }
        return $classes;
    }

    public function admin_enqueue_scripts( $hook_suffix ) {

        // Hook suffix of the AdminShell submenu page: yaycommerce (parent) + yayloyalty (slug).
        $allow_hook_suffixes = [ 'yaycommerce_page_yayloyalty' ];

        if ( ! in_array( $hook_suffix, $allow_hook_suffixes, true ) ) {
            return;
        }

        wp_localize_script(
            ScriptName::PAGE_SETTINGS,
            LocalizeHelper::VAR_ADMIN,
            LocalizeHelper::get_admin_data(),
        );

        wp_localize_script(
            ScriptName::PAGE_SETTINGS,
            LocalizeHelper::VAR_META,
            LocalizeHelper::get_meta(),
        );

        wp_enqueue_script( ScriptName::PAGE_SETTINGS );
        wp_enqueue_style( ScriptName::STYLE_SETTINGS );
    }
}
