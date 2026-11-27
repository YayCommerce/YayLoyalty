<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Repositories\CustomerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The bulk Adjust-points dialog's facts: how many customers a filter
 * matches and the points the selected customers hold. A
 * single customer is read from `GET /customers/{id}` instead, so this never
 * returns anyone's identity.
 *
 * POST despite being a read, so the filter set travels as one JSON object.
 * Gated at MANAGE_POINTS, not VIEW: liability is only shown on the adjust
 * screen.
 */
final class CustomerAdjustPreviewRestController extends AbstractRestController {

    /** @var CustomerRepository */
    private $customers;

    public function __construct( CustomerRepository $customers ) {
        $this->customers = $customers;
    }

    /** A sub-route of /customers, not a resource of its own. */
    public function get_rest_base(): string {
        return 'customers';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/adjust/preview',
            [
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'preview' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                    'args'                => [
                        'filters'     => [
                            'description' => __( 'Customers-list filters a "select all matching" selection came from. Omit for a ticked list, which knows its own count.', 'yayloyalty' ),
                            'type'        => 'object',
                            'properties'  => [
                                'search'        => [ 'type' => 'string' ],
                                'balance_range' => [
                                    'type' => 'string',
                                    'enum' => CustomerFilters::BALANCE_RANGES,
                                ],
                                'expiring_soon' => [
                                    'type' => 'string',
                                    'enum' => CustomerFilters::EXPIRY_RANGES,
                                ],
                                'tier'          => [ 'type' => 'string' ],
                            ],
                        ],
                        'user_ids'    => [
                            'description' => __( 'A ticked list: the customers whose points are summed.', 'yayloyalty' ),
                            'type'        => 'array',
                            'items'       => [ 'type' => 'integer' ],
                            'default'     => [],
                        ],
                        'exclude_ids' => [
                            'description' => __( 'Customers unticked after "select all matching".', 'yayloyalty' ),
                            'type'        => 'array',
                            'items'       => [ 'type' => 'integer' ],
                            'default'     => [],
                        ],
                    ],
                ],
            ]
        );
    }

    /** @return array<string, mixed> */
    public function preview( \WP_REST_Request $request ): array {
        $filters     = $request->get_param( 'filters' );
        $user_ids    = self::ids( $request->get_param( 'user_ids' ) );
        $exclude_ids = self::ids( $request->get_param( 'exclude_ids' ) );
        $criteria    = is_array( $filters ) && empty( $user_ids ) ? $this->criteria( $filters ) : null;

        $selection = $this->customers->selection_liability( $criteria, $user_ids, $exclude_ids );

        return [
            'count'                     => null === $criteria ? null : $this->customers->count_list( $criteria ),
            'selected_liability_points' => $selection['liability'],
            'selected_has_negative'     => $selection['has_negative'],
        ];
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private static function ids( $value ): array {
        return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
    }

    /**
     * WP REST validates `filters.*` against the enums above but does not run
     * sanitize_callback on sub-properties, so search and tier are sanitized here.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function criteria( array $filters ): array {
        $filters['search'] = sanitize_text_field( (string) ( $filters['search'] ?? '' ) );
        $filters['tier']   = sanitize_text_field( (string) ( $filters['tier'] ?? '' ) );

        return CustomerRowFilters::to_criteria( $filters );
    }

    /**
     * An empty item schema: this returns a report, not a resource, so nothing here is mass-assignable.
     *
     * @return array<string, mixed>
     */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_adjust_points_preview',
            'type'       => 'object',
            'properties' => [],
        ];
    }
}
