<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Helpers\EmailsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /emails: the loyalty emails' on/off (stored by WooCommerce) and the weekly throttle.
 *
 * No get_item_schema(): the registered loyalty emails are the whitelist, so a second list here
 * would only drift from it.
 */
class EmailsRestController extends AbstractRestController {

    public function get_rest_base(): string {
        return 'emails';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/emails',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_emails' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'save_emails' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
                ],
            ]
        );
    }

    public function get_emails( \WP_REST_Request $request ) {
        return EmailsHelper::get_response();
    }

    public function save_emails( \WP_REST_Request $request ) {
        $params = $request->get_json_params();

        if ( ! is_array( $params ) || ! isset( $params['emails'] ) || ! is_array( $params['emails'] ) ) {
            return $this->error_invalid_arguments();
        }

        // Checked before any write: a rolled-back transaction does not undo a persistent object cache.
        if ( isset( $params['throttlePerWeek'] ) && ! is_numeric( $params['throttlePerWeek'] ) ) {
            return $this->error_invalid_arguments();
        }

        EmailsHelper::update_enabled( $params['emails'] );

        // Optional: a patch, same as emails — omit to leave the stored cap untouched.
        if ( isset( $params['throttlePerWeek'] ) ) {
            EmailsHelper::update_throttle_per_week( (int) $params['throttlePerWeek'] );
        }

        return EmailsHelper::get_response();
    }
}
