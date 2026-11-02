<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * Gives a paid order's missing points back from the order screen: the recovery row, then the
 * pending figure released, the earned projection restated and the customer's pending cache
 * recomputed, all in one transaction. The order note follows the commit.
 */
final class OrderPointsRecovery {

    private LedgerService $writer;

    private PendingPoints $pending;

    /** $writer is the LedgerService built in Engine\RestAPI::controllers(), a listed construction site. */
    public function __construct( LedgerService $writer, PendingPoints $pending ) {
        $this->writer  = $writer;
        $this->pending = $pending;
    }

    /**
     * Writes the recovery for $order's current customer. The caller passes the order as the database
     * holds it (read after PendingPoints::forget_cached()) and has checked its OrderPointsState; the
     * writer checks again under the customer lock.
     *
     * @param \WC_Order $order    A fresh read, so its customer is not a stale cached one.
     * @param int       $actor_id The admin, from the session.
     * @return Outcome LedgerService::recover_order()'s: awarded, duplicate or skipped( REASON_ORDER_HAS_POINTS ).
     * @throws \InvalidArgumentException When points is outside 1 to 1,000,000.
     * @throws \YayLoyalty\Database\QueryException When a write fails (rolled back).
     */
    public function recover( \WC_Order $order, int $points, string $note, int $actor_id ): Outcome {
        $order_id    = (int) $order->get_id();
        $customer_id = (int) $order->get_customer_id();
        $actor       = get_userdata( $actor_id );
        $actor_name  = $actor instanceof \WP_User ? $actor->display_name : '';

        return Transaction::run(
            function () use ( $customer_id, $order_id, $points, $note, $actor_id, $actor_name ): Outcome {
                $outcome = $this->writer->recover_order( $customer_id, $order_id, $points, $note, $actor_id );

                if ( Outcome::AWARDED !== $outcome->get_status() ) {
                    return $outcome;
                }

                $this->pending->release( $order_id );
                $this->pending->restate_earned( $order_id, $outcome->get_entry() );
                $this->pending->resync_customer( $customer_id );

                Transaction::after_commit(
                    static function () use ( $order_id, $points, $actor_name, $note ): void {
                        $order = wc_get_order( $order_id );

                        if ( $order instanceof \WC_Order ) {
                            /* translators: 1: points, 2: admin display name, 3: the admin's reason. */
                            $order->add_order_note( sprintf( __( '%1$d points added manually by %2$s: %3$s.', 'yayloyalty' ), $points, $actor_name, $note ) );
                        }
                    }
                );

                return $outcome;
            }
        );
    }
}
