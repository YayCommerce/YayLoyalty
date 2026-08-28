<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Settings REST endpoints: GET/POST /wp-json/yayloyalty/v1/settings
 */
class SettingsRestController extends BaseRestController {
    use SingletonTrait;

    protected function __construct() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_settings' ], $request ),
                    'permission_callback' => [ $this, 'permission_callback' ],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'save_settings' ], $request ),
                    'permission_callback' => [ $this, 'permission_callback' ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/mark-reviewed',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'mark_reviewed' ], $request ),
                'permission_callback' => [ $this, 'permission_callback' ],
            ]
        );
    }

    public function permission_callback(): bool {
        return current_user_can( 'manage_woocommerce' );
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
