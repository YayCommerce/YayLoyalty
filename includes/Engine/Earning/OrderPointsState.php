<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Where one order's points stand, for the order screen's box, its REST route and the orders list.
 * Read only, and decided by the order alone: an order is missing points when it is completed, has a
 * customer, has no EARN row (for anyone) and still shows that its award should have paid: an
 * unreleased staged figure (A), earned meta the ledger lacks (C), or no sign the award ever ran while a
 * campaign would pay it now (D: completed while the plugin was inactive or not yet installed). A caller that must see the database rather
 * than the object cache reads the order with PendingPoints::fresh_order().
 */
final class OrderPointsState {

    /** The order is not in a paid status. */
    public const NOT_PAID = 'not_paid';

    /** The order has no customer. */
    public const GUEST = 'guest';

    /** The order has live earned points. */
    public const EARNED = 'earned';

    /** Paid but not completed: only completion awards, so a staged figure is waiting, not missing. */
    public const AWAITING_COMPLETION = 'awaiting_completion';

    /** Completed, but its award never completed: OrderPointsRetry runs it again. */
    public const MISSING = 'missing';

    /** Neither earned nor missing (no campaign applied, reversed, refunded): any grant goes through Adjust points. */
    public const NO_POINTS = 'no_points';

    private string $status;

    private int $figure;

    private int $net_earned;

    private function __construct( string $status, int $figure, int $net_earned ) {
        $this->status     = $status;
        $this->figure     = $figure;
        $this->net_earned = $net_earned;
    }

    /** The first matching state wins, in this order: not paid, guest, earned, awaiting completion, then missing or no points. */
    /**
     * @param OrderAward|null $award Detects signal D (an order the plugin never processed) by previewing the
     *                               award; without it such an order resolves as NO_POINTS.
     */
    public static function resolve( \WC_Order $order, LedgerRepository $ledger, ?OrderAward $award = null ): self {
        if ( ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true ) ) {
            return new self( self::NOT_PAID, 0, 0 );
        }

        if ( (int) $order->get_customer_id() < 1 ) {
            return new self( self::GUEST, 0, 0 );
        }

        $summary = $ledger->order_points_summary( (int) $order->get_id() );

        if ( $summary['net'] > 0 ) {
            return new self( self::EARNED, 0, $summary['net'] );
        }

        if ( OrderStatus::AWARD !== $order->get_status() ) {
            return new self( self::AWAITING_COMPLETION, PendingPoints::unreleased( $order ), $summary['net'] );
        }

        // Any EARN row means the award did run; a reversal since is final, by design.
        if ( $summary['earned'] > 0 ) {
            return new self( self::NO_POINTS, 0, $summary['net'] );
        }

        // Signal A, the staged figure never released; else C, earned meta the ledger lacks.
        $figure = PendingPoints::unreleased( $order );

        if ( $figure < 1 ) {
            $figure = (int) $order->get_meta( PendingPoints::META_EARNED );
        }

        // Signal D: the award never ran (no marker, no release flag), so the plugin never processed the order.
        $is_unprocessed = $figure < 1
            && '' === (string) $order->get_meta( PendingPoints::META_AWARD_RAN_AT )
            && '' === (string) $order->get_meta( PendingPoints::META_RELEASED_AT );

        if ( $figure < 1 && ! $is_unprocessed ) {
            return new self( self::NO_POINTS, 0, $summary['net'] );
        }

        // A fully refunded order owes nothing. A free order with no refund is not refunded. It queries, so it runs late.
        list( $refunds, , $is_fully_refunded ) = Reversal::refund_state( $order );

        if ( [] !== $refunds && $is_fully_refunded ) {
            return new self( self::NO_POINTS, 0, $summary['net'] );
        }

        // D counts only when a campaign would pay the order now; checkout left no figure, so MISSING carries 0.
        if ( $is_unprocessed && ( null === $award || $award->preview( $order ) < 1 ) ) {
            return new self( self::NO_POINTS, 0, $summary['net'] );
        }

        return new self( self::MISSING, $figure, 0 );
    }

    /** One of the class constants. */
    public function get_status(): string {
        return $this->status;
    }

    /** What checkout expected the award to pay, in MISSING (0 for signal D) and AWAITING_COMPLETION; else 0. */
    public function get_figure(): int {
        return $this->figure;
    }

    /** The order's earn rows net of their clawbacks; 0 before the ledger is read (not paid, guest). */
    public function get_net_earned(): int {
        return $this->net_earned;
    }

    /**
     * The REST shape of `orders/{id}/points`.
     *
     * @return array{status: string, figure: int, net_earned: int}
     */
    public function to_array(): array {
        return [
            'status'     => $this->status,
            'figure'     => $this->figure,
            'net_earned' => $this->net_earned,
        ];
    }
}
