<?php
namespace YayLoyalty\Engine\Register;

use YayLoyalty\Utils\SingletonTrait;
use YayLoyalty\Engine\Register\ScriptName;

/**
 * Register in Development Mode — loads the admin app straight from the Vite
 * dev server so saving a file hot-reloads the WP admin screen (React Fast
 * Refresh). Port must match DEV_PORT in apps/admin/vite.config.ts.
 * Will get deleted in production builds.
 */
class RegisterDev {
    use SingletonTrait;

    public const DEV_SERVER = 'http://localhost:3030';

    /** Hooks Initialization */
    protected function __construct() {
        add_action( 'admin_footer', [ $this, 'render_dev_refresh' ], 5 );

        add_action( 'init', [ $this, 'register_all_scripts' ] );
    }

    public function render_dev_refresh() {
        echo '<script type="module">
        import RefreshRuntime from "' . esc_url( self::DEV_SERVER ) . '/@react-refresh"
        RefreshRuntime.injectIntoGlobalHook(window)
        window.$RefreshReg$ = () => {}
        window.$RefreshSig$ = () => (type) => type
        window.__vite_plugin_react_preamble_installed__ = true
        </script>';
    }

    public function register_all_scripts() {
        $deps = [ 'react', 'react-dom', 'wp-element', 'wp-components', 'wp-hooks', 'wp-i18n', 'wp-date' ];

        wp_register_script( ScriptName::PAGE_SETTINGS, self::DEV_SERVER . '/main.tsx', $deps, null, true );
        wp_set_script_translations( ScriptName::PAGE_SETTINGS, 'yayloyalty', YAYLOYALTY_PLUGIN_DIR . 'languages' );
    }
}
