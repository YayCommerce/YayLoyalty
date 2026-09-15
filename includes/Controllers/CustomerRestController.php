<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\LedgerService;

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

    /**
     * The Customers-list-only search/filter params — `search` already lives on
     * `get_collection_params()`, so only the customer-specific ones are added
     * here. Values/enums mirror `customers-page.filter-rows.ts` exactly.
     *
     * @return array<string, mixed>
     */
    private function list_filter_params(): array {
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
                'description'       => __( 'Loyalty tier to filter by (no tiers exist yet — always matches zero rows).', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => [ 'all', 'Alpine', 'Summit', 'Explorer' ],
                'default'           => 'all',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
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
     * One list row — deliberately not `Models\Customer::to_array()`: this is
     * a JOIN projection carrying `name`/`email`, which the customer entity
     * has no place for. `$wpdb` returns every column as a string, so casting
     * here is mandatory (the admin app parses with zod `z.number().int()`,
     * which rejects `"640"`).
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
     * "Which filter should I drop?" — one `count_list()` call per candidate
     * `CustomerRowFilters::candidates_for_no_match()` builds, kept only when
     * non-zero and sorted by count descending.
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
