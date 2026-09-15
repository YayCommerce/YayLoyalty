<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Helpers\CustomerSegmentsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Pro-only: POST /yayloyalty/v1/customers/segments — save a Customers-page
 * saved segment, return the full list.
 *
 * Shares the base of a resource it does not own (`customers`), same
 * reasoning as CampaignPreviewRestController: registered through
 * `yayloyalty_rest_controllers`, so Lite has no knowledge of it and the
 * route is simply absent — a 404, not a 403.
 *
 * No GET route: the list also reaches the SPA via
 * `yayloyalty_localize_admin_data` (see YayLoyaltyPro::add_segments()), so
 * this POST returning the fresh list is enough for the SPA to never refetch.
 */
final class CustomerSegmentsRestController extends AbstractRestController {

    /**
     * Owns a sub-route of /customers rather than a resource of its own, so
     * it inherits that resource's capability identity — see the `title`
     * note on define_item_schema() below.
     */
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
     * No item schema: this route returns a collection, not a resource, and
     * registers no write args here (the `args` above are declared directly
     * on the route instead, since get_write_args() has nothing to filter).
     *
     * `title` set explicitly because `customers` is a non-unique
     * `$rest_base` — CustomerRestController shares it — so any future
     * `yayloyalty_rest_item_schema` subscriber matching only on the base
     * would also reach this route unless it checks `title` too.
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
