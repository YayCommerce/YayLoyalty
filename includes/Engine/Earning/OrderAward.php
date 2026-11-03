<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Earning\Listeners\PreviewLog;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * A completed order's award: one EarningService::award() per order trigger, a note for each outcome,
 * then the staged figure released. OrderListener runs it on `completed`; OrderPointsRetry runs the same
 * code when an admin retries an award that never completed, so both write exactly the same rows.
 */
final class OrderAward {

    private EarningService $service;

    private PendingPoints $pending;

    /** For the reversed-earlier check on a duplicate. */
    private LedgerRepository $ledger;

    public function __construct( EarningService $service, PendingPoints $pending, LedgerRepository $ledger ) {
        $this->service = $service;
        $this->pending = $pending;
        $this->ledger  = $ledger;
    }

    /**
     * Calls award() once per order trigger and notes each outcome on the order.
     *
     * @return Outcome[] One per trigger, in trigger order.
     * @throws \InvalidArgumentException When the order has no creation date.
     */
    public function run( \WC_Order $order ): array {
        $order_id = (int) $order->get_id();
        $outcomes = [];

        foreach ( self::triggers( $order ) as $trigger ) {
            $outcome = $this->service->award( (string) $trigger, ContextFactory::from_order( $order ) );

            PreviewLog::write_outcome( 'OrderListener', 'order #' . $order_id . ' ' . $trigger, $outcome );
            $this->note( $order, $outcome );

            $outcomes[] = $outcome;
        }

        // Completed ends staging whatever the triggers answered: a skipped award would otherwise leave pending forever.
        $this->pending->release( $order_id );

        return $outcomes;
    }

    /**
     * The points run() would award now, summed over the same triggers, without writing. 0 when no
     * campaign applies or the order has no creation date.
     */
    public function preview( \WC_Order $order ): int {
        try {
            $ctx = ContextFactory::from_order( $order );
        } catch ( \InvalidArgumentException $e ) {
            return 0;
        }

        $points = 0;

        foreach ( self::triggers( $order ) as $trigger ) {
            $result  = $this->service->preview( (string) $trigger, $ctx );
            $points += $result->is_awarded() ? $result->get_points() : 0;
        }

        return $points;
    }

    /** @return string[] */
    private static function triggers( \WC_Order $order ): array {
        /**
         * Triggers one completed order is awarded for, in order. Internal seam: Pro appends its order triggers.
         *
         * @param string[]  $triggers CampaignTrigger values.
         * @param \WC_Order $order
         */
        return (array) apply_filters( 'yayloyalty_order_award_triggers', [ CampaignTrigger::PURCHASE ], $order );
    }

    /**
     * Tells the merchant what an award did. Silent for a guest (every guest
     * order would say so) and for a plain duplicate (a re-fired status).
     */
    private function note( \WC_Order $order, Outcome $outcome ): void {
        $entry = $outcome->get_entry();

        switch ( $outcome->get_status() ) {
            case Outcome::AWARDED:
                $campaign = $outcome->get_evaluation() ? $outcome->get_evaluation()->get_campaign() : null;
                /* translators: 1: points, 2: campaign name */
                $order->add_order_note( sprintf( __( 'Earned %1$d points (campaign: %2$s).', 'yayloyalty' ), $entry->get_points(), $campaign ? $campaign->get_name() : '-' ) );

                // EarningService::AWARD_POINTS_FILTER lowered the award below what the campaign evaluated.
                $held_back = $outcome->held_back();

                if ( $held_back > 0 ) {
                    /* translators: %d: points */
                    $order->add_order_note( sprintf( __( '%d points were held back by an earning limit.', 'yayloyalty' ), $held_back ) );
                }
                break;
            case Outcome::SKIPPED:
                if ( EarningService::REASON_LIMIT_REACHED === $outcome->get_reason() ) {
                    /* translators: %d: points */
                    $order->add_order_note( sprintf( __( 'No points earned: an earning limit held back all %d points.', 'yayloyalty' ), $outcome->held_back() ) );
                } elseif ( LedgerService::REASON_ORDER_HAS_POINTS === $outcome->get_reason() ) {
                    $order->add_order_note( __( 'No points earned: this order\'s points already went to another customer.', 'yayloyalty' ) );
                } elseif ( ProgramGate::REASON_NO_CUSTOMER !== $outcome->get_reason() ) {
                    /* translators: %s: reason code */
                    $order->add_order_note( sprintf( __( 'No points earned: %s.', 'yayloyalty' ), $outcome->get_reason() ) );
                }
                break;
            case Outcome::DUPLICATE:
                if ( $this->ledger->sum_refunded_for( $entry ) > 0 ) {
                    $order->add_order_note( __( 'Points for this order were reversed earlier and are not awarded again (adjust manually if needed).', 'yayloyalty' ) );
                }
                break;
        }
    }
}
