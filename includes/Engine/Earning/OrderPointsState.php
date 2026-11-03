<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Where one order's points stand, for the order screen's box, its REST route and the orders list.
 * Read only, and decided by the order alone: an order is missing points when it is completed, has a
 * customer, has no EARN row (for anyone) and still shows that its award should have paid: an
 * unreleased staged figure, or earned meta the ledger lacks. A caller that must see the database rather
 * than the object cache calls PendingPoints::forget_cached() before reading the order.
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
    public static function resolve( \WC_Order $order, LedgerRepository $ledger ): self {
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

        // A fully refunded order owes nothing. A free order with no refund is not refunded.
        list( $refunds, , $is_fully_refunded ) = Reversal::refund_state( $order );

        if ( [] !== $refunds && $is_fully_refunded ) {
            return new self( self::NO_POINTS, 0, $summary['net'] );
        }

        $figure = PendingPoints::unreleased( $order );

        if ( $figure < 1 ) {
            $figure = (int) $order->get_meta( PendingPoints::META_EARNED );
        }

        return $figure > 0 ? new self( self::MISSING, $figure, 0 ) : new self( self::NO_POINTS, 0, $summary['net'] );
    }

    /** One of the class constants. */
    public function get_status(): string {
        return $this->status;
    }

    /** What checkout expected the award to pay, in MISSING and AWAITING_COMPLETION; else 0. */
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
