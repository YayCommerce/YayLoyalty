<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Referrals\ReferralSettings;

defined( 'ABSPATH' ) || exit;

/** GET/POST /referral/settings: the referral program's settings. Saving sends only the changed fields. */
final class ReferralSettingsRestController extends AbstractRestController {

    public function get_rest_base(): string {
        return 'referral';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/referral/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_settings' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'save_settings' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                ],
            ]
        );
    }

    /** @return array<string, mixed> */
    public function get_settings( \WP_REST_Request $request ): array {
        return ReferralSettings::get();
    }

    /** @return array<string, mixed>|\WP_Error */
    public function save_settings( \WP_REST_Request $request ) {
        $input = $request->get_json_params();

        if ( ! is_array( $input ) ) {
            return $this->error_invalid_arguments();
        }

        $field = ReferralSettings::invalid_field( $input );

        if ( null !== $field ) {
            /* translators: %s: the settings field's key, e.g. hold_days */
            return $this->error_invalid_arguments( sprintf( __( 'The referral setting %s is out of range.', 'yayloyalty' ), $field ) );
        }

        return ReferralSettings::update( $input );
    }
}
