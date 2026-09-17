<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * Customers endpoints: the list (search/filter/sort/paginate), one
 * customer's aggregates, and manual adjustment. Filter and sort semantics
 * belong to CustomerRowFilters/CustomerRowSort; this only maps request to
 * their shapes and rows to the wire. No CustomerService: only LedgerService
 * may hold both repositories.
 */
final class CustomerRestController extends AbstractRestController {

    /** @var CustomerRepository */
    private $customers;

    /** @var LedgerService */
    private $ledger_service;

    public function __construct( CustomerRepository $customers, LedgerService $ledger_service ) {
        $this->customers      = $customers;
        $this->ledger_service = $ledger_service;
    }

    public function get_rest_base(): string {
        return 'customers';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_items' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => array_merge(
                        $this->get_collection_params(),
                        $this->list_filter_params(),
                        // Replaces the base free-text `orderby` with CustomerRowSort's keys.
                        [
                            'orderby' => [
                                'description'       => __( 'Column to sort the Customers list by.', 'yayloyalty' ),
                                'type'              => 'string',
                                'enum'              => [ 'name', 'balance', 'pending', 'lifetime', 'last_activity', 'expires' ],
                                'default'           => 'balance',
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                        ]
                    ),
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_customer' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => [ 'id' => $this->user_id_param() ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)/adjust',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'adjust_points' ], $request ),
                'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                'args'                => [
                    'id'     => $this->user_id_param(),
                    'points' => [
                        'description' => __( 'Signed number of points to grant or deduct.', 'yayloyalty' ),
                        'type'        => 'integer',
                        'required'    => true,
                    ],
                    'note'   => [
                        'description'       => __( 'Reason for the adjustment, shown in history.', 'yayloyalty' ),
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * Sanity cap on one manual adjustment, administrators included.
     * Adjustments are not idempotent, so a client retrying after a timeout
     * double-grants; this bounds the damage.
     */
    private function max_adjustment(): int {
        /**
         * Largest absolute point value a single manual adjustment may carry.
         *
         * @param int $max
         */
        return (int) apply_filters( 'yayloyalty_max_manual_adjustment', 1000000 );
    }

    /**
     * Customer-specific filter params; enums mirror customers-page.filter-rows.ts.
     *
     * @return array<string, mixed>
     */
    private function list_filter_params(): array {
        return [
            'balance_range' => [
                'description'       => __( 'Balance bucket to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => CustomerFilters::BALANCE_RANGES,
                'default'           => CustomerFilters::BALANCE_RANGES[0],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'expiring_soon' => [
                'description'       => __( 'Points-expiry window to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => CustomerFilters::EXPIRY_RANGES,
                'default'           => CustomerFilters::EXPIRY_RANGES[0],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'tier'          => [
                'description'       => __( 'Loyalty tier to filter by (no tiers exist yet — always matches zero rows).', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => CustomerFilters::TIER_RANGES,
                'default'           => CustomerFilters::TIER_RANGES[0],
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /** The list. A zero-row result carries `suggestions` so the SPA need not ask "what should I drop?" per filter. */
    public function get_items( \WP_REST_Request $request ) {
        $filters = CustomerRowFilters::from_request( $request );
        $order   = CustomerRowSort::to_order(
            (string) $request->get_param( 'orderby' ),
            (string) $request->get_param( 'order' )
        );

        $result = $this->customers->paginate_list(
            CustomerRowFilters::to_criteria( $filters ),
            $order,
            (int) $request->get_param( 'page' ),
            (int) $request->get_param( 'per_page' )
        );

        $envelope = $this->respond_paginated(
            [
                'items'    => array_map( [ $this, 'format_row' ], $result['items'] ),
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
            ]
        );

        if ( 0 === $result['total'] ) {
            $envelope['suggestions'] = $this->no_match_suggestions( $filters );
        }

        return $envelope;
    }

    /**
     * One list row: a JOIN projection with name/email, not Customer::to_array().
     * Casts are mandatory: $wpdb returns strings and the SPA's zod schema rejects "640".
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_row( array $row ): array {
        return [
            'user_id'          => (int) $row['user_id'],
            'name'             => (string) $row['name'],
            'email'            => (string) $row['email'],
            'balance'          => (int) $row['balance'],
            'pending_points'   => (int) $row['pending_points'],
            'lifetime_earned'  => (int) $row['lifetime_earned'],
            'last_activity_at' => $row['last_activity_at'],
            'expires_at'       => $row['expires_at'],
        ];
    }

    /**
     * "Which filter should I drop?": one count per candidate, non-zero only, by count descending.
     *
     * @param array<string, string> $filters
     * @return array<int, array{key: string, count: int}>
     */
    private function no_match_suggestions( array $filters ): array {
        $suggestions = [];

        foreach ( CustomerRowFilters::candidates_for_no_match( $filters ) as $key => $candidate ) {
            $count = $this->customers->count_list( CustomerRowFilters::to_criteria( $candidate ) );

            if ( $count > 0 ) {
                $suggestions[] = [
                    'key'   => $key,
                    'count' => $count,
                ];
            }
        }

        usort( $suggestions, fn( $a, $b ) => $b['count'] <=> $a['count'] );

        return $suggestions;
    }

    public function get_customer( \WP_REST_Request $request ) {
        $user_id = (int) $request['id'];

        $customer = $this->customers->find_by_user_id( $user_id );

        if ( null === $customer ) {
            // No activity is a member with nothing yet, not a 404.
            return [
                'user_id'           => $user_id,
                'balance'           => 0,
                'pending_points'    => 0,
                'lifetime_earned'   => 0,
                'lifetime_redeemed' => 0,
                'last_activity_at'  => null,
                'expires_at'        => null,
                'joined_at'         => null,
                'updated_at'        => null,
            ];
        }

        return $customer->to_array();
    }

    public function adjust_points( \WP_REST_Request $request ) {
        $user_id = (int) $request['id'];
        $points  = (int) $request['points'];
        $note    = (string) $request['note'];

        if ( 0 === $points ) {
            return $this->error_invalid_arguments(
                __( 'An adjustment must be a non-zero number of points.', 'yayloyalty' )
            );
        }

        $max = $this->max_adjustment();

        if ( abs( $points ) > $max ) {
            return $this->error_invalid_arguments(
                sprintf(
                    /* translators: %s: maximum number of points */
                    __( 'A single adjustment cannot exceed %s points.', 'yayloyalty' ),
                    number_format_i18n( $max )
                )
            );
        }

        $within_ceiling = $this->assert_ceiling( $points );

        if ( is_wp_error( $within_ceiling ) ) {
            return $within_ceiling;
        }

        // actor_id comes from the session, never the request body.
        $entry = $this->ledger_service->adjust( $user_id, $points, $note, get_current_user_id() );

        return $entry->to_array();
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_customer',
            'type'       => 'object',
            'properties' => [
                'user_id'           => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'balance'           => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'pending_points'    => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'lifetime_earned'   => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'lifetime_redeemed' => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'last_activity_at'  => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'expires_at'        => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'joined_at'         => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'updated_at'        => [
                    'type'     => [ 'string', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
