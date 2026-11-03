<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\OrderPointsRecovery;
use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * POST /orders/{id}/points: gives an order's missing points back once, and answers with where the
 * order's points then stand. The POST takes no body: it recovers only a known figure (MISSING_KNOWN), with the default
 * note. Any other manual grant goes through Customers › Adjust points. It reads the order after
 * dropping its cached copy, so stale meta left by a rolled-back award cannot hide the missing points.
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
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'recover' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                    'args'                => [ 'id' => $id_arg ],
                ],
            ]
        );
    }

    /** @return array<string, mixed>|\WP_Error */
    public function recover( \WP_REST_Request $request ) {
        $order = $this->fresh_order( (int) $request['id'] );

        if ( null === $order ) {
            return $this->order_not_found();
        }

        $state = OrderPointsState::resolve( $order, $this->ledger );

        switch ( $state->get_status() ) {
            case OrderPointsState::RECOVERED:
                return $this->already_recovered();
            case OrderPointsState::EARNED:
                return $this->order_has_points();
            case OrderPointsState::MISSING_KNOWN:
                break;
            default:
                // Not completed, guest, or nothing says how many points are missing: not a recovery.
                return $this->conflict( 'yayloyalty_not_eligible', __( 'Only a completed order with a customer and a known missing figure can get its points back here. Use YayLoyalty › Customers › Adjust points instead.', 'yayloyalty' ) );
        }

        // The figure as is and the default note; actor_id comes from the session. No request field sets any of them.
        $outcome = $this->recovery->recover( $order, $state->get_figure(), self::default_note(), get_current_user_id() );

        if ( Outcome::DUPLICATE === $outcome->get_status() ) {
            return $this->already_recovered();
        }

        if ( Outcome::SKIPPED === $outcome->get_status() && LedgerService::REASON_ORDER_HAS_POINTS === $outcome->get_reason() ) {
            return $this->order_has_points();
        }

        $fresh = $this->fresh_order( (int) $order->get_id() );

        return OrderPointsState::resolve( null === $fresh ? $order : $fresh, $this->ledger )->to_array();
    }

    /** The note every recovery records. */
    private static function default_note(): string {
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
