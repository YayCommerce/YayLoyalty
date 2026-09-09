<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * GET  /yayloyalty/v1/customers/{id}         — cached aggregates
 * POST /yayloyalty/v1/customers/{id}/adjust  — manual adjustment
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
            '/customers/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_customer' ], $request ),
                    'permission_callback' => [ $this, 'permission_callback' ],
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
                'permission_callback' => [ $this, 'permission_callback' ],
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
     * run to 50s. The ceiling bounds the damage. It does not replace the
     * capability question (see the plan's open questions).
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

        // actor_id always comes from the session, never from the request body.
        $entry = $this->ledger_service->adjust( $user_id, $points, $note, get_current_user_id() );

        return $entry->to_array();
    }

    public function get_item_schema(): array {
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
