<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\PendingPoints;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps customers.pending_points with the order's customer when an admin reassigns an order that still
 * holds an unreleased staged figure. pending_points is the sum over a customer's current orders
 * (PendingPoints::resync_customer()), so without this the old customer keeps the figure for good and the
 * new one's release takes it from points they never had.
 *
 * Before the save it notes the old customer, while WooCommerce still holds it in the order's data; after
 * the save it recomputes both customers from order meta.
 */
final class OrderCustomerChangeListener {

    private PendingPoints $pending;

    /** @var array<int, array{0: int, 1: int}> Order id => [ old customer, new customer ], between the two hooks. */
    private array $changes = [];

    public function __construct( PendingPoints $pending ) {
        $this->pending = $pending;
    }

    /** Call once at boot. */
    public function register(): void {
        add_action( 'woocommerce_before_order_object_save', [ $this, 'on_before_save' ], 10, 1 );
        add_action( 'woocommerce_after_order_object_save', [ $this, 'on_after_save' ], 10, 1 );
    }

    /** @param \WC_Order|mixed $order */
    public function on_before_save( $order ): void {
        if ( ! $order instanceof \WC_Order || $order->get_id() < 1 ) {
            return;
        }

        $changes = $order->get_changes();

        if ( ! array_key_exists( 'customer_id', $changes ) || PendingPoints::unreleased( $order ) < 1 ) {
            return;
        }

        $old = (int) ( $order->get_data()['customer_id'] ?? 0 );
        $new = (int) $changes['customer_id'];

        if ( $old !== $new ) {
            $this->changes[ (int) $order->get_id() ] = [ $old, $new ];
        }
    }

    /** @param \WC_Order|mixed $order */
    public function on_after_save( $order ): void {
        if ( ! $order instanceof \WC_Order || ! isset( $this->changes[ (int) $order->get_id() ] ) ) {
            return;
        }

        $order_id = (int) $order->get_id();
        $users    = $this->changes[ $order_id ];

        unset( $this->changes[ $order_id ] );

        ListenerGuard::for_order(
            'OrderCustomerChangeListener',
            'resync pending',
            $order_id,
            function () use ( $users ): void {
                foreach ( $users as $user_id ) {
                    if ( $user_id > 0 ) {
                        $this->pending->resync_customer( $user_id );
                    }
                }
            }
        );
    }
}
