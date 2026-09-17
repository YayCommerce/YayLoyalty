<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Helpers\CustomerSegmentsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * POST/DELETE /customers/segments: save or remove a saved segment, both
 * returning the remaining list. No GET: the list arrives in the localize
 * payload. DELETE takes `name` in the body, since a name is arbitrary text.
 */
final class CustomerSegmentsRestController extends AbstractRestController {

    /** A sub-route of /customers, not a resource of its own. */
    public function get_rest_base(): string {
        return 'customers';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/segments',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'save' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                    'args'                => [
                        'name'    => [
                            'description' => __( 'Segment name.', 'yayloyalty' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                        'filters' => [
                            'description' => __( 'Filter set to save (Customers-page RowFilters minus search).', 'yayloyalty' ),
                            'type'        => 'object',
                            'required'    => true,
                        ],
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'remove' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                    'args'                => [
                        'name' => [
                            'description' => __( 'Name of the segment to remove.', 'yayloyalty' ),
                            'type'        => 'string',
                            'required'    => true,
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @return array<int, array{name: string, filters: array<string, string>}>|\WP_Error
     */
    public function save( \WP_REST_Request $request ) {
        $name    = CustomerSegmentsHelper::sanitize_name( (string) $request->get_param( 'name' ) );
        $filters = $request->get_param( 'filters' );

        if ( '' === $name || ! is_array( $filters ) ) {
            return $this->error_invalid_arguments( __( 'A segment needs a name.', 'yayloyalty' ) );
        }

        $reason = CustomerSegmentsHelper::rejection_reason( $name );

        if ( 'max_segments' === $reason ) {
            return $this->error_invalid_arguments( __( 'You have reached the maximum of 50 saved segments.', 'yayloyalty' ) );
        }

        if ( 'duplicate' === $reason ) {
            return $this->error_invalid_arguments( __( 'A segment with that name already exists.', 'yayloyalty' ) );
        }

        return CustomerSegmentsHelper::append_segment( $name, $filters );
    }

    /**
     * @return array<int, array{name: string, filters: array<string, string>}>|\WP_Error
     */
    public function remove( \WP_REST_Request $request ) {
        $name = CustomerSegmentsHelper::sanitize_name( (string) $request->get_param( 'name' ) );

        if ( '' === $name ) {
            return $this->error_invalid_arguments( __( 'A segment needs a name.', 'yayloyalty' ) );
        }

        $segments = CustomerSegmentsHelper::remove_segment( $name );

        if ( null === $segments ) {
            return $this->error_not_found( __( 'No segment with that name.', 'yayloyalty' ) );
        }

        return $segments;
    }

    /**
     * No item schema: this returns a collection. `title` is set because the
     * `customers` base is shared, so a schema filter must match on it.
     *
     * @return array<string, mixed>
     */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_customer_segments',
            'type'       => 'object',
            'properties' => [],
        ];
    }
}
