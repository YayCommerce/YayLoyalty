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

    /** @var LedgerRepository Read-only here — counts.ledger only. All writes stay LedgerService's. */
    private $ledger;

    /** @var RewardRepository Read-only — the drawer's points→money conversion rate. */
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

        $scenario = CustomerDrawerFixture::requested( $request );

        if ( null !== $scenario ) {
            return CustomerDrawerFixture::customer( $scenario, $user_id );
        }

        return $this->format_customer( $user_id, $this->customers->find_by_user_id( $user_id ) );
    }

    /**
     * A user with no activity is a member with nothing yet, not a 404 — the
     * model's own defaults are that empty shape.
     *
     * @return array<string, mixed>
     */
    private function format_customer( int $user_id, ?Customer $customer ): array {
        $base = ( $customer ?? new Customer( $user_id ) )->to_array();

        $user         = get_userdata( $user_id );
        $ledger_count = $this->ledger->paginate_for_user(
            $user_id,
            [
                'page'     => 1,
                'per_page' => 1,
            ]
        )['total'];

        return $base + [
            'name'                     => $user ? $user->display_name : '',
            'email'                    => $user ? $user->user_email : '',
            // No `deleted_at` column exists (docs/data-model.md), so the real
            // path can never report a deleted account — 4a is reachable only
            // through `?scenario=deleted`. Not scheduled: Wave B is UI work on
            // fixtures and does not add the column.
            'deleted_at'               => null,
            'counts'                   => [
                'ledger'      => $ledger_count,
                // Zero is the TRUE count here, not a placeholder: no activity,
                // redemptions or referrals table exists (docs/data-model.md),
                // so there is nothing to count. The fixture path carries real
                // per-scenario counts instead — see `DrawerProfiles::rows()`.
                // Each of these becomes a query when its own table ships; they
                // are three separate features, not one change.
                'activity'    => 0,
                'redemptions' => 0,
                'referrals'   => 0,
            ],
            // Real path can never populate this: it's keyed off a deletion
            // that only `?scenario=deleted` can ever report (see `deleted_at`
            // above). Structured null, not an absent field — customer.schema.ts
            // parses both paths identically.
            'loose_ends'               => null,
            // Real path can never populate this either: it needs `lots`,
            // which doesn't exist yet (docs/data-model.md). `expires_at`
            // above is all the real path knows about a customer's expiry.
            'expiry'                   => null,
            // Real path can never populate this either: assembling the
            // earned/spent/reversed story means walking the ledger
            // backwards, which is its own piece of work and not this wave's.
            'negative_balance'         => null,
            // Restored minimally (F09 Wave B phase 03) after `worth` was
            // removed wholesale — the drawer's expiry/negative-balance
            // panels need this rate for their money-equivalent figures.
            'points_per_currency_unit' => $this->rewards->points_per_currency_unit(),
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
            'properties' => CustomerItemSchema::properties(),
        ];
    }
}
