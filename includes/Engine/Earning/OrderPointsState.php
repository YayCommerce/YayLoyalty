<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Where one order's points stand, for the order screen's box, its REST route and the orders list.
 * Read only. The ledger is the truth: order meta that claims earned points without an EARN row
 * counts as missing. A caller that must see the database rather than the object cache calls
 * PendingPoints::forget_cached() before reading the order.
 */
final class OrderPointsState {

    /** The order is not in a paid status. */
    public const NOT_PAID = 'not_paid';

    /** The order has no customer. */
    public const GUEST = 'guest';

    /** Paid but not completed: only completion awards, so a staged figure is waiting, not missing. */
    public const AWAITING_COMPLETION = 'awaiting_completion';

    /** The order has live earned points. */
    public const EARNED = 'earned';

    /** LedgerService::recover_order() already gave the order's points back. */
    public const RECOVERED = 'recovered';

    /** Points are missing, and the figure says how many. */
    public const MISSING_KNOWN = 'missing_known';

    /** Nothing says how many points are missing: not a recovery; the box links to Adjust points. */
    public const MISSING_UNKNOWN = 'missing_unknown';

    private string $status;

    private int $figure;

    private int $net_earned;

    private ?LedgerEntry $recovery;

    private bool $is_recovery_reversed;

    private function __construct( string $status, int $figure, int $net_earned, ?LedgerEntry $recovery, bool $is_recovery_reversed ) {
        $this->status               = $status;
        $this->figure               = $figure;
        $this->net_earned           = $net_earned;
        $this->recovery             = $recovery;
        $this->is_recovery_reversed = $is_recovery_reversed;
    }

    /** The first matching state wins, in this order: not paid, guest, recovered, earned, awaiting completion, then missing. */
    public static function resolve( \WC_Order $order, LedgerRepository $ledger ): self {
        if ( ! in_array( $order->get_status(), wc_get_is_paid_statuses(), true ) ) {
            return new self( self::NOT_PAID, 0, 0, null, false );
        }

        if ( (int) $order->get_customer_id() < 1 ) {
            return new self( self::GUEST, 0, 0, null, false );
        }

        $order_id = (int) $order->get_id();
        $summary  = $ledger->order_points_summary( $order_id );
        $recovery = $ledger->find_recovery_for_order( $order_id );

        if ( null !== $recovery ) {
            $is_reversed = $ledger->sum_refunded_for( $recovery, false ) >= $recovery->get_points();

            return new self( self::RECOVERED, 0, $summary['net'], $recovery, $is_reversed );
        }

        if ( $summary['net'] > 0 ) {
            return new self( self::EARNED, 0, $summary['net'], null, false );
        }

        // Points are missing only once the order has reached the status that awards them.
        if ( OrderStatus::AWARD !== $order->get_status() ) {
            return new self( self::AWAITING_COMPLETION, PendingPoints::unreleased( $order ), $summary['net'], null, false );
        }

        // The first known figure: the unreleased staged one; else, for an order reversed then restored, what its
        // earn rows paid; else what the meta claims without a row (an award rolled back after its meta was cached).
        $figure = PendingPoints::unreleased( $order );

        if ( $figure < 1 ) {
            $figure = $summary['earned'];
        }

        if ( $figure < 1 ) {
            $figure = (int) $order->get_meta( PendingPoints::META_EARNED );
        }

        if ( $figure < 1 ) {
            return new self( self::MISSING_UNKNOWN, 0, $summary['net'], null, false );
        }

        return new self( self::MISSING_KNOWN, $figure, $summary['net'], null, false );
    }

    /** One of the class constants. */
    public function get_status(): string {
        return $this->status;
    }

    /** The points to give back in MISSING_KNOWN, the staged figure in AWAITING_COMPLETION, else 0. */
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
     * @return array{status: string, figure: int, net_earned: int, recovery: array{points: int, note: string, actor_name: string, created_at: string, reversed: bool}|null}
     */
    public function to_array(): array {
        $recovery = null;

        if ( null !== $this->recovery ) {
            $actor_id = $this->recovery->get_actor_id();
            $actor    = null === $actor_id ? false : get_userdata( $actor_id );

            $recovery = [
                'points'     => $this->recovery->get_points(),
                'note'       => (string) $this->recovery->get_note(),
                'actor_name' => $actor instanceof \WP_User ? $actor->display_name : '',
                'created_at' => Dates::to_site_date( $this->recovery->get_created_at() ),
                'reversed'   => $this->is_recovery_reversed,
            ];
        }

        return [
            'status'     => $this->status,
            'figure'     => $this->figure,
            'net_earned' => $this->net_earned,
            'recovery'   => $recovery,
        ];
    }
}
