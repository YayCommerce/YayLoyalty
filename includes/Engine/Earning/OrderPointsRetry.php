<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a completed order's award again when it never completed (OrderPointsState::MISSING): the same
 * OrderAward that `completed` runs, so the row is the order's normal purchase earning and every guard,
 * reversal and refund treats it as one. Each award commits its own transaction.
 */
final class OrderPointsRetry {

    private OrderAward $award;

    private PendingPoints $pending;

    public function __construct( OrderAward $award, PendingPoints $pending ) {
        $this->award   = $award;
        $this->pending = $pending;
    }

    /**
     * The caller passes the order as the database holds it (PendingPoints::fresh_order()) and has resolved
     * it as MISSING; the ledger re-checks under the customer lock.
     *
     * @param int $actor_id The admin, from the session.
     * @return Outcome[] OrderAward::run()'s, one per order trigger.
     * @throws \InvalidArgumentException When the order has no creation date.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function retry( \WC_Order $order, int $actor_id ): array {
        // No EARN row exists, so earned meta is a projection of nothing; mark_awarded() would add to it.
        $this->pending->forget_earned( $order );

        $outcomes = $this->award->run( $order );

        // Heals a drifted cache: pending_points is the sum of the customer's unreleased staged figures.
        $this->pending->resync_customer( (int) $order->get_customer_id() );

        foreach ( $outcomes as $outcome ) {
            if ( Outcome::AWARDED === $outcome->get_status() ) {
                $actor = Text::user_display_name( $actor_id );
                /* translators: %s: admin display name */
                $order->add_order_note( sprintf( __( 'Points award retried by %s.', 'yayloyalty' ), '' === $actor ? '-' : $actor ) );
                break;
            }
        }

        return $outcomes;
    }
}
