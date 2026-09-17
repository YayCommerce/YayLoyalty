<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Fixtures\CustomerDrawer\CustomerDrawerFixture;
use YayLoyalty\Controllers\Schemas\CustomerItemSchema;
use YayLoyalty\Models\Customer;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /yayloyalty/v1/customers                — search/filter/sort/paginate
 * GET  /yayloyalty/v1/customers/{id}            — cached aggregates
 * POST /yayloyalty/v1/customers/{id}/adjust     — manual adjustment
 *
 * The list route is real, not demo data: `CustomerRepository::paginate_list()`
 * queries `yayloyalty_customers` JOINed with `wp_users`. `CustomerRowFilters`/
 * `CustomerRowSort` still own the product's filter/sort semantics — see their
 * own docblocks — this controller only turns a request into their SQL-free
 * criteria/order shapes and a response back into wire rows.
 *
 * Read-side composition lives here rather than in a CustomerService: a service
 * that only forwarded reads to one repository would be ceremony, and it would
 * need the second repository to compose anything — which only LedgerService is
 * allowed to hold.
 */
final class CustomerRestController extends AbstractRestController {

    /** @var CustomerRepository */
    private $customers;

    /** @var LedgerService */
    private $ledger_service;

    /** @var LedgerRepository Read-only here — counts.ledger only. All writes stay LedgerService's. */
    private $ledger;

    /** @var RewardRepository Read-only — worth's conversion rate. */
    private $rewards;

    public function __construct(
        CustomerRepository $customers,
        LedgerService $ledger_service,
        LedgerRepository $ledger,
        RewardRepository $rewards
    ) {
        $this->customers      = $customers;
        $this->ledger_service = $ledger_service;
        $this->ledger         = $ledger;
        $this->rewards        = $rewards;
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
                        CustomerListFormatter::filter_params(),
                        // Overrides the base `orderby` (free-text) with the 6 real sort
                        // keys — see `CustomerRowSort`. `order` keeps the base definition.
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
                    'args'                => array_merge( [ 'id' => $this->user_id_param() ], CustomerDrawerFixture::scenario_arg() ),
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
     * Ceiling on a single manual adjustment.
     *
     * Adjustments are deliberately not idempotent, so a client that retries
     * after a timeout double-grants — ky gives up at 20s while a lock wait can
     * run to 50s. This sanity cap bounds the damage for everyone, administrators
     * included. The per-role policy ceiling is assert_ceiling(), applied after
     * it.
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
     * Real list, real DB — search/filter/sort run in SQL
     * (`CustomerRepository::paginate_list()`); this method only translates
     * the request into `CustomerRowFilters`/`CustomerRowSort`'s SQL-free
     * shapes and the result back into wire rows.
     *
     * A zero-row result also gets a `suggestions` field folded into this same
     * response (see `no_match_suggestions()`) — the admin app fires one
     * extra request per active filter to ask "what should I drop?" otherwise.
     */
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
                'items'    => array_map( [ CustomerListFormatter::class, 'format_row' ], $result['items'] ),
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
            ]
        );

        if ( 0 === $result['total'] ) {
            $envelope['suggestions'] = CustomerListFormatter::no_match_suggestions( $filters, $this->customers );
        }

        return $envelope;
    }

    public function get_customer( \WP_REST_Request $request ) {
        $user_id = (int) $request['id'];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $request['scenario'] ) ) {
            return CustomerDrawerFixture::customer( (string) $request['scenario'], $user_id );
        }

        return $this->format_customer( $user_id, $this->customers->find_by_user_id( $user_id ) );
    }

    /**
     * One `format_customer()` for both branches, so "no activity yet" and
     * "has a row" build the exact same shape once. `to_array()` already
     * defines the nine aggregate fields; the no-row default below mirrors it
     * field-for-field on purpose — a user with no activity is a member with
     * nothing yet, not a 404.
     *
     * @return array<string, mixed>
     */
    private function format_customer( int $user_id, ?Customer $customer ): array {
        $base = null !== $customer
            ? $customer->to_array()
            : [
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

        $user         = get_userdata( $user_id );
        $ppcu         = $this->rewards->points_per_currency_unit();
        $ledger_count = $this->ledger->paginate_for_user(
            $user_id,
            [
                'page'     => 1,
                'per_page' => 1,
            ]
        )['total'];

        return $base + [
            'name'       => $user ? $user->display_name : '',
            'email'      => $user ? $user->user_email : '',
            // No deleted_at column yet (docs/data-model.md) — Wave B.
            'deleted_at' => null,
            'counts'     => [
                'ledger'      => $ledger_count,
                // No activity/redemptions/referrals tables yet — Wave B.
                'activity'    => 0,
                'redemptions' => 0,
                'referrals'   => 0,
            ],
            'worth'      => [
                'currency_amount'          => PointsMath::points_to_currency( (int) $base['balance'], $ppcu ),
                'points_per_currency_unit' => $ppcu,
            ],
        ];
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

        // Role policy, after the sanity cap: the ceiling is per-role and
        // administrators bypass it; the cap above binds everyone.
        $within_ceiling = $this->assert_ceiling( $points );

        if ( is_wp_error( $within_ceiling ) ) {
            return $within_ceiling;
        }

        // actor_id always comes from the session, never from the request body.
        $entry = $this->ledger_service->adjust( $user_id, $points, $note, get_current_user_id() );

        return $entry->to_array();
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_customer',
            'type'       => 'object',
            'properties' => CustomerItemSchema::properties(),
        ];
    }
}
