<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\Reversal;

defined( 'ABSPATH' ) || exit;

/**
 * The order events that take points back: cancel and trash (full), the
 * `refunded` status, refunds created and deleted. Every reversal is keyed,
 * so a trash that reaches two hooks, or a re-fired refund, reverses once.
 * Each one is noted on the order with the points it moved.
 */
final class OrderReversalListener {

    private PendingPoints $pending;

    private Reversal $reversal;

    public function __construct( PendingPoints $pending, Reversal $reversal ) {
        $this->pending  = $pending;
        $this->reversal = $reversal;
    }

    /**
     * Attaches the refund and trash hooks. Refunds arrive as refund objects,
     * never as a status. Trash is announced by `woocommerce_trash_order`
     * because HPOS writes the status directly without
     * `woocommerce_order_status_changed`; `trashed_post` covers the legacy
     * posts screen. The cancelled/trash/refunded statuses reach this class
     * through OrderListener::route().
     */
    public function register(): void {
        add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );
        add_action( 'woocommerce_refund_deleted', [ $this, 'on_refund_deleted' ], 10, 2 );
        add_action( 'woocommerce_trash_order', [ $this, 'on_order_trashed' ], 10, 1 );
        add_action( 'trashed_post', [ $this, 'on_order_trashed' ], 10, 1 );
    }

    /** `woocommerce_order_refunded` adapter: claws back this refund's share of each earn row. */
    public function on_order_refunded( int $order_id, int $refund_id ): void {
        ListenerGuard::for_order(
            'OrderReversalListener',
            'refund #' . $refund_id,
            $order_id,
            function () use ( $order_id, $refund_id ): void {
                $order = wc_get_order( $order_id );

                if ( $order instanceof \WC_Order ) {
                    /* translators: %d: refund id */
                    $this->note_reversed( $order, $this->reversal->refund( $order, $refund_id ), sprintf( __( 'refund #%d', 'yayloyalty' ), $refund_id ) );
                }
            }
        );
    }

    /** `woocommerce_refund_deleted` adapter: gives back what that refund clawed. */
    public function on_refund_deleted( int $refund_id, int $order_id ): void {
        ListenerGuard::for_order(
            'OrderReversalListener',
            'refund #' . $refund_id . ' delete',
            $order_id,
            function () use ( $order_id, $refund_id ): void {
                $outcomes = $this->reversal->refund_deleted( $refund_id );
                $restored = self::points_moved( $outcomes );
                $order    = wc_get_order( $order_id );

                self::log_outcomes( $order_id, 'refund #' . $refund_id . ' deleted', $outcomes );

                if ( $restored > 0 && $order instanceof \WC_Order ) {
                    /* translators: 1: refund id, 2: points */
                    $order->add_order_note( sprintf( __( 'Refund #%1$d deleted — %2$d points restored.', 'yayloyalty' ), $refund_id, $restored ) );
                }
            }
        );
    }

    /** `woocommerce_trash_order` / `trashed_post` adapter; anything that is not an order is ignored. */
    public function on_order_trashed( int $order_id ): void {
        if ( ! OrderUtil::is_order( $order_id, wc_get_order_types() ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( $order instanceof \WC_Order ) {
            ListenerGuard::for_order( 'OrderReversalListener', 'cancel', $order_id, fn() => $this->on_cancelled( $order ) );
        }
    }

    /** An order that will never award: releases pending and claws back whatever it already earned; nothing re-awards it. */
    public function on_cancelled( \WC_Order $order ): void {
        $this->pending->release( (int) $order->get_id() );
        $this->note_reversed( $order, $this->reversal->full_order( $order ), __( 'order cancelled', 'yayloyalty' ) );
        // TODO(F08): restore a redemption still applied to the order (Engine\Redemption), keyed on the order.
    }

    /** The `refunded` status: releases pending always; reverses in full only when no refund object exists (Reversal::refunded_status()). */
    public function on_status_refunded( \WC_Order $order ): void {
        $this->pending->release( (int) $order->get_id() );
        $this->note_reversed( $order, $this->reversal->refunded_status( $order ), __( 'order refunded', 'yayloyalty' ) );
    }

    /**
     * Notes how many points a reversal took back; silent when it took none.
     *
     * @param Outcome[] $outcomes
     */
    private function note_reversed( \WC_Order $order, array $outcomes, string $reason ): void {
        self::log_outcomes( (int) $order->get_id(), $reason, $outcomes );

        $reversed = self::points_moved( $outcomes );

        if ( $reversed > 0 ) {
            /* translators: 1: points, 2: reason, e.g. "refund #12" */
            $order->add_order_note( sprintf( __( 'Reversed %1$d points (%2$s).', 'yayloyalty' ), $reversed, $reason ) );
        }
    }

    /**
     * Writes one debug line per outcome.
     *
     * @param Outcome[] $outcomes
     */
    private static function log_outcomes( int $order_id, string $subject, array $outcomes ): void {
        foreach ( $outcomes as $outcome ) {
            PreviewLog::write_outcome( 'OrderReversalListener', 'order #' . $order_id . ' ' . $subject, $outcome );
        }
    }

    /**
     * The points the written rows moved, unsigned.
     *
     * @param Outcome[] $outcomes
     */
    private static function points_moved( array $outcomes ): int {
        $points = 0;

        foreach ( $outcomes as $outcome ) {
            if ( Outcome::AWARDED === $outcome->get_status() ) {
                $points += abs( $outcome->get_entry()->get_points() );
            }
        }

        return $points;
    }
}
