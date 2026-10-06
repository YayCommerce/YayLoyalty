<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The bulk Adjust-points dialog's store-level facts: how many customers a
 * filter matches, the store-wide liability, and the points→money rate. A
 * single customer is read from `GET /customers/{id}` instead, so this never
 * returns anyone's identity.
 *
 * POST despite being a read, so the filter set travels as one JSON object.
 * Gated at MANAGE_POINTS, not VIEW: store-wide liability is only shown on
 * the adjust screen.
 */
final class CustomerAdjustPreviewRestController extends AbstractRestController {

    /** @var CustomerRepository */
    private $customers;

    /** @var DashboardRepository */
    private $dashboard;

    /** @var RewardRepository */
    private $rewards;

    public function __construct( CustomerRepository $customers, DashboardRepository $dashboard, RewardRepository $rewards ) {
        $this->customers = $customers;
        $this->dashboard = $dashboard;
        $this->rewards   = $rewards;
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
                        'filters' => [
                            // No tier: the SPA has no Tier filter, and no customer has a tier yet.
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
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /** @return array<string, mixed> */
    public function preview( \WP_REST_Request $request ): array {
        $filters = $request->get_param( 'filters' );

        return [
            'count'                    => is_array( $filters ) ? $this->count_matching( $filters ) : null,
            'liability_points'         => $this->dashboard->liability_points(),
            'points_per_currency_unit' => $this->rewards->points_per_currency_unit(),
        ];
    }

    /**
     * COUNT-only: never fetches rows. WP REST validates `filters.*` against
     * the enums above but does not run sanitize_callback on sub-properties,
     * so search is sanitized here.
     *
     * @param array<string, mixed> $filters
     */
    private function count_matching( array $filters ): int {
        $filters['search'] = sanitize_text_field( (string) ( $filters['search'] ?? '' ) );

        return $this->customers->count_list( CustomerRowFilters::to_criteria( $filters ) );
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
