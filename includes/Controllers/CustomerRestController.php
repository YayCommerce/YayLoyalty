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
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * Customers endpoints: the list, one customer's aggregates, and manual adjustment.
 * No CustomerService: only LedgerService writes customer and ledger rows.
 */
final class CustomerRestController extends AbstractRestController {

    /** @var CustomerRepository */
    private $customers;

    /** @var LedgerService */
    private $ledger_service;

    /** @var LedgerRepository Read-only here, for counts.ledger. LedgerService makes every write. */
    private $ledger;

    /** @var CustomerMetaRepository Read-only: counts.activity, the deletion snapshot and the list's identity fallback. */
    private $meta;

    public function __construct(
        CustomerRepository $customers,
        LedgerService $ledger_service,
        LedgerRepository $ledger,
        CustomerMetaRepository $meta
    ) {
        $this->customers      = $customers;
        $this->ledger_service = $ledger_service;
        $this->ledger         = $ledger;
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
                    'args'                => [ 'id' => $this->member_id_param() ],
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

    public function get_items( \WP_REST_Request $request ) {
        return CustomerListFormatter::respond_list( $request, $this, $this->customers, $this->meta );
    }

    public function get_customer( \WP_REST_Request $request ) {
        $user_id = (int) $request['id'];

        return $this->format_customer( $user_id, $this->customers->find_by_user_id( $user_id ) );
    }

    /** A user with no activity is a member with nothing yet, not a 404. The model's defaults are that empty shape. */
    private function format_customer( int $user_id, ?Customer $customer ): array {
        $base = ( $customer ?? new Customer( $user_id ) )->to_array();
        $user = get_userdata( $user_id );

        $activity_count = $this->meta->count_activity( $user_id );
        // Also gated on the WP user being gone now, so a reinstated id self-heals with no "undelete" event.
        $deletion = false === $user ? $this->meta->latest( $user_id, ActivityEvent::ACCOUNT_DELETED ) : null;

        return CustomerItemFormatter::format(
            $base,
            CustomerItemFormatter::live_identity( $user ),
            $this->ledger->count_for_user( $user_id ),
            $activity_count,
            $deletion
        );
    }

    public function adjust_points( \WP_REST_Request $request ) {
        $points = (int) $request['points'];

        if ( 0 === $points ) {
            return $this->error_invalid_arguments( __( 'An adjustment must be a non-zero number of points.', 'yayloyalty' ) );
        }

        // actor_id comes from the session, never the request body.
        $entry = $this->ledger_service->adjust(
            (int) $request['id'],
            $points,
            (string) $request['note'],
            get_current_user_id(),
            (bool) $request['adjust_lifetime']
        );

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
