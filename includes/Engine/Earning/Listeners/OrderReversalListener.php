<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use Automattic\WooCommerce\Utilities\OrderUtil;
use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Engine\Redemption\Reversal as RedemptionReversal;
use YayLoyalty\Engine\Rewards\Result as RedeemResult;

defined( 'ABSPATH' ) || exit;

/**
 * The order events that take points back: cancel and trash (full), the
 * `refunded` status, refunds created and deleted. Every reversal is keyed,
 * so a trash that reaches two hooks, or a re-fired refund, reverses once.
 * Each one is noted on the order with the points it moved.
 * The same events also return the points the order redeemed (Engine\Redemption\Reversal),
 * before the clawback and in the same transaction, so neither lands without the other.
 * An order that comes back from cancelled or the trash is debited those points again.
 */
final class OrderReversalListener {

    private PendingPoints $pending;

    private Reversal $reversal;

    private RedemptionReversal $redemptions;

    public function __construct( PendingPoints $pending, Reversal $reversal, RedemptionReversal $redemptions ) {
        $this->pending     = $pending;
        $this->reversal    = $reversal;
        $this->redemptions = $redemptions;
    }

    /**
     * HPOS trashes an order without `woocommerce_order_status_changed`, so trash has its own hooks:
     * `woocommerce_trash_order`, plus `trashed_post` for the legacy posts screen.
     * The cancelled, trash and refunded statuses arrive through OrderListener::route().
     */
    public function register(): void {
        add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );
        add_action( 'woocommerce_refund_deleted', [ $this, 'on_refund_deleted' ], 10, 2 );
        add_action( 'woocommerce_trash_order', [ $this, 'on_order_trashed' ], 10, 1 );
        add_action( 'trashed_post', [ $this, 'on_order_trashed' ], 10, 1 );
    }

    /** `woocommerce_order_refunded` adapter: returns this refund's share of the redeemed points, then claws back its share of each earn row. */
    public function on_order_refunded( int $order_id, int $refund_id ): void {
        ListenerGuard::for_order(
            'OrderReversalListener',
            'refund #' . $refund_id,
            $order_id,
            function () use ( $order_id, $refund_id ): void {
                $order = wc_get_order( $order_id );

                if ( ! $order instanceof \WC_Order ) {
                    return;
                }

                $this->restore_then_reverse(
                    $order,
                    fn(): array => $this->redemptions->refunded( $order, $refund_id ),
                    fn(): array => $this->reversal->refund( $order, $refund_id ),
                    /* translators: %d: refund id */
                    sprintf( __( 'refund #%d', 'yayloyalty' ), $refund_id )
                );
            }
        );
    }

    /**
     * `woocommerce_refund_deleted` adapter: gives back what that refund clawed, then debits the redeemed points it returned.
     * The give-back runs first: it only adds points, so the debit that follows lands on the higher balance.
     */
    public function on_refund_deleted( int $refund_id, int $order_id ): void {
        ListenerGuard::for_order(
            'OrderReversalListener',
            'refund #' . $refund_id . ' delete',
            $order_id,
            function () use ( $order_id, $refund_id ): void {
                list( $outcomes, $debited ) = Transaction::run(
                    fn(): array => [ $this->reversal->refund_deleted( $refund_id ), $this->redemptions->refund_deleted( $refund_id, $order_id ) ]
                );

                $restored = self::points_moved( $outcomes );
                $order    = wc_get_order( $order_id );

                self::log_outcomes( $order_id, 'refund #' . $refund_id . ' deleted', $outcomes );

                if ( ! $order instanceof \WC_Order ) {
                    return;
                }

                if ( $restored > 0 ) {
                    /* translators: 1: refund id, 2: points */
                    $order->add_order_note( sprintf( __( 'Refund #%1$d deleted — %2$d points restored.', 'yayloyalty' ), $refund_id, $restored ) );
                }

                /* translators: %d: refund id */
                $this->note_debited( $order, $debited, sprintf( __( 'refund #%d deleted', 'yayloyalty' ), $refund_id ) );
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

    /**
     * An order that will never award: releases pending, returns the points it redeemed and claws back
     * whatever it already earned; nothing re-awards it.
     */
    public function on_cancelled( \WC_Order $order ): void {
        $this->pending->release( (int) $order->get_id() );

        $this->restore_then_reverse(
            $order,
            fn(): array => $this->redemptions->cancelled( $order ),
            fn(): array => $this->reversal->full_order( $order ),
            __( 'order cancelled', 'yayloyalty' )
        );
    }

    /**
     * The `refunded` status: releases pending always; restores and reverses only when no refund object exists
     * (Reversal::refunded_status() on both sides).
     */
    public function on_status_refunded( \WC_Order $order ): void {
        $this->pending->release( (int) $order->get_id() );

        $this->restore_then_reverse(
            $order,
            fn(): array => $this->redemptions->refunded_status( $order ),
            fn(): array => $this->reversal->refunded_status( $order ),
            __( 'order refunded', 'yayloyalty' )
        );
    }

    /**
     * An order back from cancelled or the trash (OrderListener decides): debits the redeemed points again.
     * Earned points are not re-awarded here or anywhere: the earn key is spent. A debit that could not be
     * written is noted, so the merchant can adjust by hand.
     */
    public function on_uncancelled( \WC_Order $order ): void {
        $outcomes = $this->redemptions->uncancelled( $order );

        $this->note_debited( $order, $outcomes, __( 'order restored', 'yayloyalty' ) );

        foreach ( $outcomes as $outcome ) {
            if ( Outcome::SKIPPED !== $outcome->get_status() ) {
                continue;
            }

            if ( RedeemResult::REASON_INSUFFICIENT_BALANCE === $outcome->get_reason() ) {
                $order->add_order_note( __( 'The points discount on this order was not debited again: the customer\'s balance is too low. Adjust manually if needed.', 'yayloyalty' ) );
            } elseif ( RedemptionReversal::REASON_KEY_SPENT === $outcome->get_reason() ) {
                $order->add_order_note( __( 'The points discount on this order was already debited again once and is not debited a second time. Adjust manually if needed.', 'yayloyalty' ) );
            }
        }
    }

    /**
     * Returns the redeemed points, then claws back the earned ones, in one transaction and in that order:
     * the restore row sits before the clawback row, and a failure in either leaves neither. Notes both.
     *
     * @param callable(): Outcome[] $restore The Redemption\Reversal call for this event.
     * @param callable(): Outcome[] $reverse The earn Reversal call for the same event.
     */
    private function restore_then_reverse( \WC_Order $order, callable $restore, callable $reverse, string $reason ): void {
        list( $restored, $reversed ) = Transaction::run( fn(): array => [ $restore(), $reverse() ] );

        $this->note_restored( $order, $restored, $reason );
        $this->note_reversed( $order, $reversed, $reason );
    }

    /**
     * Notes how many redeemed points a reversal returned; silent when it returned none.
     *
     * @param Outcome[] $outcomes
     */
    private function note_restored( \WC_Order $order, array $outcomes, string $reason ): void {
        /* translators: 1: points, 2: reason, e.g. "refund #12" */
        $this->note_points( $order, $outcomes, $reason, __( 'Restored %1$d redeemed points (%2$s).', 'yayloyalty' ) );
    }

    /**
     * Notes how many redeemed points were taken again; silent when none were.
     *
     * @param Outcome[] $outcomes
     */
    private function note_debited( \WC_Order $order, array $outcomes, string $reason ): void {
        /* translators: 1: points, 2: reason, e.g. "order restored" */
        $this->note_points( $order, $outcomes, $reason, __( 'Debited %1$d redeemed points again (%2$s).', 'yayloyalty' ) );
    }

    /**
     * Notes how many points a reversal took back; silent when it took none.
     *
     * @param Outcome[] $outcomes
     */
    private function note_reversed( \WC_Order $order, array $outcomes, string $reason ): void {
        /* translators: 1: points, 2: reason, e.g. "refund #12" */
        $this->note_points( $order, $outcomes, $reason, __( 'Reversed %1$d points (%2$s).', 'yayloyalty' ) );
    }

    /**
     * Logs the outcomes and notes the points they moved on the order; silent when they moved none.
     *
     * @param Outcome[] $outcomes
     * @param string    $template A translated sprintf format taking 1: points, 2: reason.
     */
    private function note_points( \WC_Order $order, array $outcomes, string $reason, string $template ): void {
        self::log_outcomes( (int) $order->get_id(), $reason, $outcomes );

        $points = self::points_moved( $outcomes );

        if ( $points > 0 ) {
            $order->add_order_note( sprintf( $template, $points, $reason ) );
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
