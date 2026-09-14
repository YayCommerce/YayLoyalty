<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /yayloyalty/v1/customers               — TEMPORARY fixed demo rows, see get_items()
 * GET  /yayloyalty/v1/customers/{id}          — cached aggregates
 * POST /yayloyalty/v1/customers/{id}/adjust   — manual adjustment
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
                        $this->get_demo_filter_params(),
                        // Overrides the base `orderby` (free-text) with the 8 real sort
                        // keys — see `CustomerRowSort`. `order` keeps the base definition.
                        [
                            'orderby' => [
                                'description'       => __( 'Column to sort the Customers list by.', 'yayloyalty' ),
                                'type'               => 'string',
                                'enum'               => [ 'name', 'tier', 'balance', 'pending', 'lifetime', 'last_activity', 'expires', 'referrals' ],
                                'default'            => 'balance',
                                'sanitize_callback'  => 'sanitize_text_field',
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

    public function get_customer( \WP_REST_Request $request ) {
        $user_id = (int) $request['id'];

        $customer = $this->customers->find_by_user_id( $user_id );

        if ( null === $customer ) {
            // A user with no activity is a member with nothing yet, not a 404.
            // Report zeroes rather than forcing the client to special-case it.
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

    /**
     * The Customers-list-only search/filter params — `search` already lives on
     * `get_collection_params()`, so only the customer-specific ones are added
     * here. Values/enums mirror `customers-page.filter-rows.ts` exactly.
     *
     * @return array<string, mixed>
     */
    private function get_demo_filter_params(): array {
        return [
            'balance_range' => [
                'description'       => __( 'Balance bucket to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => [ 'any', 'negative', '0-1000', '1000-10000', '10000-plus' ],
                'default'           => 'any',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'expiring_soon' => [
                'description'       => __( 'Points-expiry window to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => [ 'off', '30', '90', 'none' ],
                'default'           => 'off',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'tier'          => [
                'description'       => __( 'Loyalty tier to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => [ 'all', 'Alpine', 'Summit', 'Explorer' ],
                'default'           => 'all',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * TEMPORARY DEMO DATA — NOT A REAL QUERY. See `CustomerDemoRows`.
     *
     * `CustomerRepository` has no list/paginate method yet (only
     * find_by_user_id(), ensure_row(), lock_and_get(), apply_delta() — see the
     * class docblock), so there is no real Customers list to serve. This lets
     * the admin Customers screen fetch over a genuine HTTP round trip (and
     * gives its loading skeleton something real to wait on) instead of
     * importing a hardcoded array client-side.
     *
     * `page`/`per_page` ARE applied, and so is search/filter (via
     * `CustomerRowFilters` — the product's real filter rules, just run
     * in-memory here) and sort (via `CustomerRowSort`, same reasoning) —
     * filtered, sorted, THEN sliced, all against the same fixed 30 rows — so
     * the admin list gets correct results across the whole (demo) dataset
     * instead of only ever searching/sorting whatever page already loaded.
     *
     * A zero-row result also gets a `suggestions` field folded into this same
     * response (see `CustomerRowFilters::suggestions_for_no_match()`) — the
     * admin app used to fire one extra request per active filter to ask
     * "what should I drop?"; now that answer rides along with the empty
     * result instead of costing a round trip of its own.
     *
     * DELETE this method and `CustomerDemoRows`, and replace the body with a
     * real `CustomerRepository::paginate_filtered()`-style call (likely
     * reusing `CustomerRowFilters`/`CustomerRowSort`'s rules as SQL `WHERE`/
     * `ORDER BY` clauses instead of deleting them), once that repository
     * method exists.
     */
    public function get_items( \WP_REST_Request $request ) {
        $filters = [
            'search'        => (string) $request->get_param( 'search' ),
            'balance_range' => (string) $request->get_param( 'balance_range' ),
            'expiring_soon' => (string) $request->get_param( 'expiring_soon' ),
            'tier'          => (string) $request->get_param( 'tier' ),
        ];

        $all_rows = CustomerDemoRows::all();
        $rows     = CustomerRowFilters::apply( $all_rows, $filters );
        $rows     = CustomerRowSort::apply(
            $rows,
            (string) $request->get_param( 'orderby' ),
            (string) $request->get_param( 'order' )
        );

        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, (int) $request->get_param( 'per_page' ) ?: 20 );

        $envelope = $this->respond_paginated(
            [
                'items'    => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
                'total'    => count( $rows ),
                'page'     => $page,
                'per_page' => $per_page,
            ]
        );

        if ( 0 === count( $rows ) ) {
            $envelope['suggestions'] = CustomerRowFilters::suggestions_for_no_match( $all_rows, $filters );
        }

        return $envelope;
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
            'properties' => [
                'user_id'           => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                // Every aggregate is server-owned: it is derived from the
                // ledger and must never be settable from a request.
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
