<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Schemas\OrderPointsRouteArgs;
use YayLoyalty\Engine\Earning\OrderPointsRecovery;
use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * GET and POST /orders/{id}/points: where an order's points stand, and giving its missing points
 * back once. Both read the order after dropping its cached copy, so stale meta left by a rolled-back
 * award cannot hide the missing points.
 */
final class OrderPointsRestController extends AbstractRestController {

    private OrderPointsRecovery $recovery;

    private LedgerRepository $ledger;

    private PendingPoints $pending;

    public function __construct( OrderPointsRecovery $recovery, LedgerRepository $ledger, PendingPoints $pending ) {
        $this->recovery = $recovery;
        $this->ledger   = $ledger;
        $this->pending  = $pending;
    }

    public function get_rest_base(): string {
        return 'orders';
    }

    public function register_routes(): void {
        $id_arg = [
            'description'       => __( 'WooCommerce order id.', 'yayloyalty' ),
            'type'              => 'integer',
            'required'          => true,
            'sanitize_callback' => 'absint',
        ];

        register_rest_route(
            self::REST_NAMESPACE,
            '/orders/(?P<id>[\d]+)/points',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_state' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                    'args'                => [ 'id' => $id_arg ],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'recover' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                    'args'                => [ 'id' => $id_arg ] + OrderPointsRouteArgs::recover(),
                ],
            ]
        );
    }

    /** @return array<string, mixed>|\WP_Error */
    public function get_state( \WP_REST_Request $request ) {
        $order = $this->fresh_order( (int) $request['id'] );

        if ( null === $order ) {
            return $this->order_not_found();
        }

        return OrderPointsState::resolve( $order, $this->ledger )->to_array();
    }

    /** @return array<string, mixed>|\WP_Error */
    public function recover( \WP_REST_Request $request ) {
        $order = $this->fresh_order( (int) $request['id'] );

        if ( null === $order ) {
            return $this->order_not_found();
        }

        $state = OrderPointsState::resolve( $order, $this->ledger );
        $note  = trim( (string) $request['note'] );

        switch ( $state->get_status() ) {
            case OrderPointsState::NOT_PAID:
            case OrderPointsState::GUEST:
                return $this->conflict( 'yayloyalty_not_eligible', __( 'Points can be added only to a paid order with a customer.', 'yayloyalty' ) );
            case OrderPointsState::RECOVERED:
                return $this->already_recovered();
            case OrderPointsState::EARNED:
                return $this->order_has_points();
            case OrderPointsState::MISSING_KNOWN:
                // Confirm mode: the figure as is, never the request's points.
                $points = $state->get_figure();
                $note   = '' === $note ? self::default_note() : $note;
                break;
            default:
                $points = null === $request['points'] ? 0 : (int) $request['points'];

                if ( $points < 1 || $points > LedgerService::MAX_RECOVERY_POINTS ) {
                    return new \WP_Error(
                        'yayloyalty_invalid_points',
                        /* translators: %s: the most points allowed, formatted */
                        sprintf( __( 'Enter the points to add, a whole number from 1 to %s.', 'yayloyalty' ), number_format_i18n( LedgerService::MAX_RECOVERY_POINTS ) ),
                        [ 'status' => 400 ]
                    );
                }

                if ( '' === $note ) {
                    return new \WP_Error( 'yayloyalty_invalid_note', __( 'Enter a reason for adding these points.', 'yayloyalty' ), [ 'status' => 400 ] );
                }
        }

        // actor_id comes from the session, never the request body.
        $outcome = $this->recovery->recover( $order, $points, $note, get_current_user_id() );

        if ( Outcome::DUPLICATE === $outcome->get_status() ) {
            return $this->already_recovered();
        }

        if ( Outcome::SKIPPED === $outcome->get_status() && LedgerService::REASON_ORDER_HAS_POINTS === $outcome->get_reason() ) {
            return $this->order_has_points();
        }

        $fresh = $this->fresh_order( (int) $order->get_id() );

        return OrderPointsState::resolve( null === $fresh ? $order : $fresh, $this->ledger )->to_array();
    }

    /** The reason a Confirm-mode recovery records when the admin gives none; the box shows it as the placeholder. */
    public static function default_note(): string {
        return __( 'Recovered missing order points', 'yayloyalty' );
    }

    /** The order as the database holds it, past any cached copy, or null. */
    private function fresh_order( int $order_id ): ?\WC_Order {
        $this->pending->forget_cached( $order_id );

        $order = wc_get_order( $order_id );

        return $order instanceof \WC_Order ? $order : null;
    }

    private function order_not_found(): \WP_Error {
        return new \WP_Error( 'yayloyalty_order_not_found', __( 'No such order.', 'yayloyalty' ), [ 'status' => 404 ] );
    }

    private function already_recovered(): \WP_Error {
        return $this->conflict( 'yayloyalty_already_recovered', __( 'This order\'s points were already added manually. Use YayLoyalty › Customers › Adjust points instead.', 'yayloyalty' ) );
    }

    private function order_has_points(): \WP_Error {
        return $this->conflict( 'yayloyalty_order_has_points', __( 'This order already earned its points.', 'yayloyalty' ) );
    }

    private function conflict( string $code, string $message ): \WP_Error {
        return new \WP_Error( $code, $message, [ 'status' => 409 ] );
    }
}
