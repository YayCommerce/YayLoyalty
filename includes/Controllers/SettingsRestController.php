<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/** GET/POST /settings and POST /settings/mark-reviewed. */
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

        if ( is_array( $settings ) ) {
            // The tiers route writes these behind its own capability; manage_settings alone must not turn tiers on.
            $settings = array_diff_key( $settings, array_flip( SettingsHelper::TIER_KEYS ) );
        }

        if ( ! is_array( $settings ) || ! SettingsHelper::is_valid( $settings ) ) {
            return $this->error_invalid_arguments();
        }

        $expiry_before = SettingsHelper::get_settings()['expiry'];
        $saved         = SettingsHelper::update_settings( $settings );

        // Re-apply the new rules to every customer's date now, not at the next daily run.
        if ( $saved['expiry'] !== $expiry_before ) {
            ExpiryRunner::queue_now();
        }

        return $saved;
    }

    public function mark_reviewed( \WP_REST_Request $request ) {
        update_option( SettingsHelper::OPTION_REVIEWED, true );
        return true;
    }
}
