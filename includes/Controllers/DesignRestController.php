<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Storefront\LauncherPreview;
use YayLoyalty\Helpers\DesignHelper;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /design, POST /design/reset and POST /design/preview.
 *
 * No get_item_schema(): the whitelist lives in DesignSchema::schema(), following the SettingsRestController
 * sibling rather than §9's per-resource rule — one whitelist for a single-option resource, not two.
 */
class DesignRestController extends AbstractRestController {

    public function get_rest_base(): string {
        return 'design';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/design',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_design' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'save_design' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/design/reset',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'reset_design' ], $request ),
                'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
            ]
        );

        // POST although it saves nothing: the unsaved design is too large for a query string.
        register_rest_route(
            self::REST_NAMESPACE,
            '/design/preview',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_read( [ $this, 'preview_design' ], $request ),
                'permission_callback' => $this->requires( Capabilities::MANAGE_SETTINGS ),
            ]
        );
    }

    public function get_design( \WP_REST_Request $request ) {
        return DesignHelper::get_design();
    }

    public function save_design( \WP_REST_Request $request ) {
        $design = $request->get_json_params();

        if ( ! is_array( $design ) ) {
            return $this->error_invalid_arguments();
        }

        return DesignHelper::update_design( $design );
    }

    /**
     * The storefront launcher drawn with the unsaved design, as two pages: the button alone and the open panel.
     * `viewer` is `guest` for the signed-out panel; anything else shows the sample member.
     *
     * @return array{collapsed: string, expanded: string}|\WP_Error
     */
    public function preview_design( \WP_REST_Request $request ) {
        $design = $request->get_param( 'design' );

        if ( ! is_array( $design ) ) {
            return $this->error_invalid_arguments();
        }

        $design    = DesignHelper::with_unsaved( $design );
        $is_member = 'guest' !== $request->get_param( 'viewer' );

        return [
            'collapsed' => LauncherPreview::document( $design, $is_member, false ),
            'expanded'  => LauncherPreview::document( $design, $is_member, true ),
        ];
    }

    /** An unknown group is not an error: DesignHelper::reset() changes nothing and returns the stored design. */
    public function reset_design( \WP_REST_Request $request ) {
        $group = $request->get_param( 'group' );

        return DesignHelper::reset( is_string( $group ) && '' !== $group ? $group : null );
    }
}
