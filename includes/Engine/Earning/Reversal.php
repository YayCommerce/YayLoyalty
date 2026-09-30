<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\BaseCalculator;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Takes earned points back: a full clawback when an order is cancelled or a
 * paid review is removed, a proportional one per refund, and a restore when
 * a refund is deleted. Works per earn row, so each trigger an order paid for
 * is reversed against its own campaign. Every write is keyed, so a re-fired
 * hook is a duplicate. There is no re-award after a reversal: the earn key
 * is spent.
 */
final class Reversal {

    private LedgerService $writer;

    private LedgerRepository $ledger;

    private CampaignRepository $campaigns;

    public function __construct( LedgerService $writer, LedgerRepository $ledger, CampaignRepository $campaigns ) {
        $this->writer    = $writer;
        $this->ledger    = $ledger;
        $this->campaigns = $campaigns;
    }

    /** Composition root; LedgerService's construction site for reversals. */
    public static function build(): self {
        $ledger = new LedgerRepository();

        return new self( new LedgerService( $ledger, new CustomerRepository() ), $ledger, new CampaignRepository() );
    }

    /**
     * Claws back everything the order earned that is not yet clawed back (cancel, trash, admin refund).
     *
     * @return Outcome[] One per earn row.
     */
    public function full_order( \WC_Order $order ): array {
        $order_id = (int) $order->get_id();

        return array_map(
            fn( LedgerEntry $earn ): Outcome => $this->writer->refund_earn( $earn, $earn->get_points(), ReferenceType::ORDER, $order_id ),
            $this->ledger->earn_rows_for_order( $order_id )
        );
    }

    /**
     * Claws back each earn row's share of what the order's refunds took so far, keyed on this refund.
     *
     * A refund with no line items (amount only) removes no base and claws back
     * nothing, unless it leaves nothing to refund: a fully refunded order owes
     * every point back.
     *
     * @return Outcome[] One per earn row.
     */
    public function refund( \WC_Order $order, int $refund_id ): array {
        $fully_refunded = (float) $order->get_remaining_refund_amount() <= 0.0;
        $outcomes       = [];

        foreach ( $this->ledger->earn_rows_for_order( (int) $order->get_id() ) as $earn ) {
            list( $original, $refunded ) = $this->bases( $order, $earn );

            $owed       = ClawbackMath::owed( $earn->get_points(), $refunded, $original, $fully_refunded );
            $outcomes[] = $this->writer->refund_earn( $earn, $owed, ReferenceType::REFUND, $refund_id );
        }

        return $outcomes;
    }

    /**
     * Restores what a deleted refund clawed back.
     *
     * @return Outcome[] One per clawback row the refund wrote.
     */
    public function refund_deleted( int $refund_id ): array {
        return array_map(
            fn( LedgerEntry $refund_row ): Outcome => $this->writer->restore_refund( $refund_row, $refund_id ),
            $this->ledger->refund_rows_for_refund( $refund_id )
        );
    }

    /** Claws back a review's whole earn; null when the review never earned. */
    public function full_review( int $comment_id ): ?Outcome {
        $earn = $this->ledger->find_earn_for_reference( ReferenceType::COMMENT, $comment_id );

        return null === $earn ? null : $this->writer->refund_earn( $earn, $earn->get_points(), ReferenceType::COMMENT, $comment_id );
    }

    /**
     * The base an earn row was scored on and how much of it the order's
     * refunds removed, cumulatively. Only lines the earn's campaign counted
     * (BaseCalculator::includes_line()), so refunding an excluded product
     * claws back nothing. Refund lines point at the original through
     * `_refunded_item_id` and carry negative totals.
     *
     * @return float[] [ original, refunded ]
     */
    private function bases( \WC_Order $order, LedgerEntry $earn ): array {
        $campaign = null === $earn->get_campaign_id() ? null : $this->campaigns->find_campaign( (int) $earn->get_campaign_id() );

        if ( null === $campaign ) {
            Log::warning( 'Reversal', 'campaign of ledger row #' . $earn->get_id() . ' is gone; every line counts toward its refund share' );
        }

        $included = [];
        $original = 0.0;

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( $item instanceof \WC_Order_Item_Product && ( null === $campaign || BaseCalculator::includes_line( $campaign, ContextFactory::line_item( $item ) ) ) ) {
                $included[ (int) $item_id ] = true;
                $original                  += (float) $item->get_total();
            }
        }

        $refunded = 0.0;

        foreach ( $order->get_refunds() as $refund ) {
            foreach ( $refund->get_items() as $line ) {
                if ( isset( $included[ (int) $line->get_meta( '_refunded_item_id' ) ] ) ) {
                    $refunded += abs( (float) $line->get_total() );
                }
            }
        }

        return [ $original, $refunded ];
    }
}
