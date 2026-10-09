<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Services\SetupService;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /setup: the setup wizard's prefill and its "Turn on my program".
 *
 * No get_item_schema(): SetupService validates the payload against the campaign and reward
 * config schemas it writes into, so a second whitelist here would only drift.
 */
class SetupRestController extends AbstractRestController {

    /** @var SetupService */
    private $setup;

    public function __construct( SetupService $setup ) {
        $this->setup = $setup;
    }

    public function get_rest_base(): string {
        return 'setup';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/setup',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_setup' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'complete_setup' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
                ],
            ]
        );
    }

    public function get_setup( \WP_REST_Request $request ) {
        return $this->setup->current();
    }

    public function complete_setup( \WP_REST_Request $request ) {
        $input = $request->get_json_params();

        if ( ! is_array( $input ) ) {
            return $this->error_invalid_arguments();
        }

        $error = $this->setup->validation_error( $input );

        if ( null !== $error ) {
            return $this->error_invalid_arguments( $error );
        }

        return $this->setup->complete( $input );
    }
}
