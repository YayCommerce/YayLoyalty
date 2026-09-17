<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Settings REST endpoints: GET/POST /wp-json/yayloyalty/v1/settings and
 * POST /wp-json/yayloyalty/v1/settings/mark-reviewed
 *
 * Routes are registered from register_routes(), not from the constructor, so
 * constructing this class has no side effects. SingletonTrait was dropped along
 * with that: it exists to make a side-effecting constructor run once, and there
 * is no longer a side effect to guard.
 */
class SettingsRestController extends AbstractRestController {

    public function get_rest_base(): string {
        return 'settings';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_settings' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'save_settings' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/mark-reviewed',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'mark_reviewed' ], $request ),
                'permission_callback' => $this->requires( Capabilities::VIEW ),
            ]
        );
    }

    public function get_settings( \WP_REST_Request $request ) {
        return SettingsHelper::get_settings();
    }

    public function save_settings( \WP_REST_Request $request ) {
        $settings = $request->get_json_params();

        if ( ! is_array( $settings ) ) {
            return $this->error_invalid_arguments();
        }

        return SettingsHelper::update_settings( $settings );
    }

    public function mark_reviewed( \WP_REST_Request $request ) {
        update_option( SettingsHelper::OPTION_REVIEWED, true );
        return true;
    }
}
