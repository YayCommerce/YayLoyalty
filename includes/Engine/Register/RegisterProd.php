<?php
namespace YayLoyalty\Engine\Register;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use YayLoyalty\Utils\SingletonTrait;
use YayLoyalty\Engine\Register\ScriptName;

/** Register in Production Mode */
class RegisterProd {
    use SingletonTrait;

    /** Hooks Initialization */
    protected function __construct() {
        add_action( 'init', [ $this, 'register_all_scripts' ] );
    }

    public function register_all_scripts() {
        $deps = [ 'react', 'react-dom', 'wp-element', 'wp-components', 'wp-hooks', 'wp-i18n', 'wp-date' ];
        wp_register_script( ScriptName::PAGE_SETTINGS, YAYLOYALTY_PLUGIN_URL . 'assets/dist/admin/js/main.js', $deps, YAYLOYALTY_VERSION, true );
        wp_set_script_translations( ScriptName::PAGE_SETTINGS, 'yayloyalty', YAYLOYALTY_PLUGIN_DIR . 'languages' );
    }
}
