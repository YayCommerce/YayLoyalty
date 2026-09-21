<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only "what am I about to adjust?" answer for the Adjust-points modal:
 * either one customer's fresh record or a filter's match count. POST despite
 * being a read — a customer-id list should not sit in a URL or a server log,
 * same reason as CampaignPreviewRestController.
 *
 * Gated at MANAGE_POINTS, not VIEW: this enumerates balances, lifetime
 * totals, e-mail addresses and store-wide liability — exactly what the
 * adjust screen exposes, so a merchant employee who cannot adjust points
 * must not be able to harvest it either.
 *
 * The `customer_ids` branch only hydrates name/e-mail for an id that has a
 * loyalty row. shop_manager holds MANAGE_POINTS by default; without that
 * rule this route would answer name/e-mail for any wp_users id, turning it
 * into a PII oracle. A per-user rate limit backs that up as defence in depth.
 */
final class CustomerAdjustPreviewRestController extends AbstractRestController {

    private const MAX_PREVIEW_IDS = 1000;

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
                        'customer_ids' => [
                            'description' => __( 'User ids this adjustment would apply to.', 'yayloyalty' ),
                            'type'        => 'array',
                            'items'       => [ 'type' => 'integer' ],
                            'maxItems'    => self::MAX_PREVIEW_IDS,
                        ],
                        'filters'      => [
                            // 'tier' is a client-side illusion (see CustomerRowFilters):
                            // any real tier matches zero rows here, same as the list. The
                            // caller is expected to substitute a balance bucket before
                            // sending — this route does not special-case tiers.
                            'description' => __( 'Customers-list filters the bulk selection came from.', 'yayloyalty' ),
                            'type'        => 'object',
                            'properties'  => array_merge(
                                [ 'search' => [ 'type' => 'string' ] ],
                                CustomerListFormatter::filter_params()
                            ),
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function preview( \WP_REST_Request $request ) {
        $limited = $this->assert_rate_limit();

        if ( is_wp_error( $limited ) ) {
            return $limited;
        }

        $ids     = $request->get_param( 'customer_ids' );
        $filters = $request->get_param( 'filters' );

        if ( null === $ids && null === $filters ) {
            return $this->error_invalid_arguments( __( 'Provide either customer_ids or filters.', 'yayloyalty' ) );
        }

        if ( null !== $ids && null !== $filters ) {
            return $this->error_invalid_arguments( __( 'Provide customer_ids or filters, not both.', 'yayloyalty' ) );
        }

        if ( null !== $ids ) {
            $clean_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $ids ) ) ) );

            if ( empty( $clean_ids ) ) {
                return $this->error_invalid_arguments( __( 'customer_ids must contain at least one valid id.', 'yayloyalty' ) );
            }

            return $this->preview_ids( $clean_ids );
        }

        return $this->preview_filters( is_array( $filters ) ? $filters : [] );
    }

    /**
     * @param int[] $ids
     * @return array<string, mixed>
     */
    private function preview_ids( array $ids ): array {
        $customer = 1 === count( $ids ) ? $this->format_customer_row( $ids[0] ) : null;

        return $this->envelope( count( $ids ), null === $customer ? [] : [ $customer ], null );
    }

    /**
     * Enrolled-only hydration is a security control, not a formatting
     * choice: without it this route answers name/e-mail for any WP user id,
     * not just members with loyalty activity. A null Customer row means "no
     * loyalty row", so it returns null upward, never a name/e-mail stub.
     *
     * @return array<string, mixed>|null
     */
    private function format_customer_row( int $user_id ): ?array {
        $customer = $this->customers->find_by_user_id( $user_id );

        if ( null === $customer ) {
            return null;
        }

        $user = get_userdata( $user_id );

        return [
            'user_id'         => $user_id,
            'name'            => $user ? (string) $user->display_name : '',
            'email'           => $user ? (string) $user->user_email : '',
            'balance'         => (int) $customer->get_balance(),
            'lifetime_earned' => (int) $customer->get_lifetime_earned(),
        ];
    }

    /**
     * COUNT-only: never fetches rows. WP REST's nested-object schema
     * validates `filters.*`'s shape but does not run sanitize_callback on
     * sub-properties, so every key is hand-normalised here rather than
     * trusted from the request.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function preview_filters( array $filters ): array {
        $clean = [
            'search'        => sanitize_text_field( (string) ( $filters['search'] ?? '' ) ),
            'balance_range' => $this->enum_or_default( $filters['balance_range'] ?? null, CustomerFilters::BALANCE_RANGES ),
            'expiring_soon' => $this->enum_or_default( $filters['expiring_soon'] ?? null, CustomerFilters::EXPIRY_RANGES ),
            'tier'          => $this->enum_or_default( $filters['tier'] ?? null, CustomerFilters::TIER_RANGES ),
        ];

        $count = $this->customers->count_list( CustomerRowFilters::to_criteria( $clean ) );

        return $this->envelope( $count, [], $this->dashboard->liability_points() );
    }

    /**
     * @param mixed    $value
     * @param string[] $allowed
     */
    private function enum_or_default( $value, array $allowed ): string {
        return in_array( (string) $value, $allowed, true ) ? (string) $value : $allowed[0];
    }

    /**
     * One shape for both branches, so they cannot drift apart.
     *
     * @param array<int, array<string, mixed>> $customers
     * @return array<string, mixed>
     */
    private function envelope( int $count, array $customers, ?int $liability_points ): array {
        return [
            'count'                    => $count,
            'customers'                => $customers,
            'liability_points'         => $liability_points,
            'points_per_currency_unit' => $this->rewards->points_per_currency_unit(),
            'fetched_at'               => Dates::now(),
        ];
    }

    /**
     * Cheap per-user throttle: this is the plugin's cheapest enumeration
     * surface, so on top of the enrolled-only rule above, a caller cannot
     * walk ids fast enough to be useful. Filterable and isolated in one
     * method so it stays trivial to reason about.
     *
     * @return true|\WP_Error
     */
    private function assert_rate_limit() {
        $user_id = get_current_user_id();
        $key     = 'yayloyalty_adjust_preview_' . $user_id;

        /**
         * Requests per 60-second window a single user may make against
         * POST /customers/adjust/preview.
         *
         * @param int $limit
         */
        $limit = (int) apply_filters( 'yayloyalty_adjust_preview_rate_limit', 60 );
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return new \WP_Error(
                'too_many_requests',
                __( 'Too many preview requests. Please wait a moment and try again.', 'yayloyalty' ),
                [ 'status' => 429 ]
            );
        }

        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

        return true;
    }

    /**
     * No item schema: this returns a report, not a resource, so nothing here is mass-assignable.
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
