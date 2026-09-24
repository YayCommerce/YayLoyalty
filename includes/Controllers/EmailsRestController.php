<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Helpers\EmailsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /emails.
 *
 * No get_item_schema(): the whitelist lives in EmailsMetadata::all_email_ids(), following the
 * DesignRestController sibling — one whitelist for a single-option resource, not two.
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

        EmailsHelper::update_emails( $params['emails'] );

        // Optional: a patch, same as emails — omit to leave the stored cap untouched.
        if ( isset( $params['throttlePerWeek'] ) ) {
            if ( ! is_numeric( $params['throttlePerWeek'] ) ) {
                return $this->error_invalid_arguments();
            }

            EmailsHelper::update_throttle_per_week( (int) $params['throttlePerWeek'] );
        }

        return EmailsHelper::get_response();
    }
}
