<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Engine\Earning\OrderPointsRetry;
use YayLoyalty\Engine\Earning\OrderPointsState;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * POST /orders/{id}/points: runs a completed order's award again when it never completed
 * (OrderPointsState::MISSING), and answers with where the order's points then stand. No body: the award
 * decides the points. Any other manual grant goes through Customers › Adjust points. It reads the order
 * after dropping its cached copy, so stale meta left by a rolled-back award cannot hide the missing points.
 */
final class OrderPointsRestController extends AbstractRestController {

    private OrderPointsRetry $retry;

    private LedgerRepository $ledger;

    public function __construct( OrderPointsRetry $retry, LedgerRepository $ledger ) {
        $this->retry  = $retry;
        $this->ledger = $ledger;
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
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'retry' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                    'args'                => [ 'id' => $id_arg ],
                ],
            ]
        );
    }

    /** @return array<string, mixed>|\WP_Error */
    public function retry( \WP_REST_Request $request ) {
        $order = PendingPoints::fresh_order( (int) $request['id'] );

        if ( null === $order ) {
            return $this->order_not_found();
        }

        $state = OrderPointsState::resolve( $order, $this->ledger );

        if ( OrderPointsState::EARNED === $state->get_status() ) {
            return $this->order_has_points();
        }

        if ( OrderPointsState::MISSING !== $state->get_status() ) {
            return $this->conflict( 'yayloyalty_not_eligible', __( 'Only a completed order whose points award never completed can be retried here. Use YayLoyalty › Customers › Adjust points instead.', 'yayloyalty' ) );
        }

        // actor_id comes from the session; the POST has no body.
        $is_awarded = false;
        $has_points = false;
        $reason     = '';

        foreach ( $this->retry->retry( $order, get_current_user_id() ) as $outcome ) {
            $is_awarded = $is_awarded || Outcome::AWARDED === $outcome->get_status();
            $has_points = $has_points || Outcome::DUPLICATE === $outcome->get_status() || LedgerService::REASON_ORDER_HAS_POINTS === $outcome->get_reason();
            $reason     = '' === $reason ? (string) $outcome->get_reason() : $reason;
        }

        if ( $is_awarded ) {
            $fresh = PendingPoints::fresh_order( (int) $order->get_id() );

            return OrderPointsState::resolve( null === $fresh ? $order : $fresh, $this->ledger )->to_array();
        }

        if ( $has_points ) {
            return $this->order_has_points();
        }

        return $this->conflict(
            'yayloyalty_not_awarded',
            /* translators: %s: reason code */
            sprintf( __( 'No points were awarded: %s. Use YayLoyalty › Customers › Adjust points instead.', 'yayloyalty' ), '' === $reason ? '-' : $reason )
        );
    }

    private function order_not_found(): \WP_Error {
        return new \WP_Error( 'yayloyalty_order_not_found', __( 'No such order.', 'yayloyalty' ), [ 'status' => 404 ] );
    }

    private function order_has_points(): \WP_Error {
        return $this->conflict( 'yayloyalty_order_has_points', __( 'This order already earned its points.', 'yayloyalty' ) );
    }

    private function conflict( string $code, string $message ): \WP_Error {
        return new \WP_Error( $code, $message, [ 'status' => 409 ] );
    }
}
