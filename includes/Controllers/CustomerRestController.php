<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Schemas\CustomerItemSchema;
use YayLoyalty\Controllers\Schemas\CustomerRouteArgs;
use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Models\Customer;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Log;
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

    /** @var CustomerMetaRepository Read-only — counts.activity, the deletion snapshot, the list's identity fallback. */
    private $meta;

    public function __construct(
        CustomerRepository $customers,
        LedgerService $ledger_service,
        LedgerRepository $ledger,
        RewardRepository $rewards,
        CustomerMetaRepository $meta
    ) {
        $this->customers      = $customers;
        $this->ledger_service = $ledger_service;
        $this->ledger         = $ledger;
        $this->rewards        = $rewards;
        $this->meta           = $meta;
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
                        CustomerRouteArgs::list_sort()
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
                    'args'                => [ 'id' => $this->user_id_param( $this->customers ) ],
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
                'args'                => [ 'id' => $this->user_id_param() ] + CustomerRouteArgs::adjust(),
            ]
        );
    }

    /** Real list, real DB — CustomerListFormatter owns the full request→response handling. */
    public function get_items( \WP_REST_Request $request ) {
        return CustomerListFormatter::respond_list( $request, $this, $this->customers, $this->meta );
    }

    public function get_customer( \WP_REST_Request $request ) {
        $user_id = (int) $request['id'];

        return $this->format_customer( $user_id, $this->customers->find_by_user_id( $user_id ) );
    }

    /** A user with no activity is a member with nothing yet, not a 404 — the model's own defaults are that empty shape. */
    private function format_customer( int $user_id, ?Customer $customer ): array {
        $base = ( $customer ?? new Customer( $user_id ) )->to_array();
        $user = get_userdata( $user_id );

        try {
            $activity_count = $this->meta->count_activity( $user_id, ActivityEvent::ALL );
            // Gated on the WP user currently being gone too — a reinstated id self-heals, no "undelete" event needed.
            $deletion = false === $user ? $this->meta->latest( $user_id, ActivityEvent::ACCOUNT_DELETED ) : null;
        } catch ( \Throwable $e ) {
            // Missing table (version not bumped) or any other failure degrades this block rather than 500ing every customer.
            Log::error( 'CustomerRestController', $e->getMessage() );
            $activity_count = 0;
            $deletion       = null;
        }

        return CustomerItemFormatter::format(
            $base,
            CustomerListFormatter::live_identity( $user ),
            $this->ledger->paginate_for_user(
                $user_id,
                [
                    'page'     => 1,
                    'per_page' => 1,
                ]
            )['total'],
            $activity_count,
            $deletion,
            $this->rewards->points_per_currency_unit()
        );
    }

    public function adjust_points( \WP_REST_Request $request ) {
        $points = (int) $request['points'];

        $invalid = $this->validate_adjustment( $points );

        if ( null !== $invalid ) {
            return $invalid;
        }

        $within_ceiling = $this->assert_ceiling( $points );

        if ( is_wp_error( $within_ceiling ) ) {
            return $within_ceiling;
        }

        // actor_id comes from the session, never the request body.
        $entry = $this->ledger_service->adjust( (int) $request['id'], $points, (string) $request['note'], get_current_user_id() );

        return $entry->to_array();
    }

    /** Non-zero and within the sanity ceiling — adjustments aren't idempotent, so a retry double-grants; this bounds the damage. */
    private function validate_adjustment( int $points ): ?\WP_Error {
        if ( 0 === $points ) {
            return $this->error_invalid_arguments( __( 'An adjustment must be a non-zero number of points.', 'yayloyalty' ) );
        }

        /** @param int $max Largest absolute point value a single manual adjustment may carry. */
        $max = (int) apply_filters( 'yayloyalty_max_manual_adjustment', 1000000 );

        if ( abs( $points ) > $max ) {
            return $this->error_invalid_arguments(
                sprintf(
                    /* translators: %s: maximum number of points */
                    __( 'A single adjustment cannot exceed %s points.', 'yayloyalty' ),
                    number_format_i18n( $max )
                )
            );
        }

        return null;
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
