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
        $refunds        = $this->refunds( (int) $order->get_id() );
        $refunded_total = array_sum( array_map( static fn( \WC_Order_Refund $refund ): float => (float) $refund->get_amount(), $refunds ) );
        $decimals       = wc_get_price_decimals();
        // Rounded like WooCommerce's own remaining amount: 0.35 + 0.10 is not >= 0.45 in floats.
        $fully_refunded = round( $refunded_total, $decimals ) >= round( (float) $order->get_total(), $decimals );
        $outcomes       = [];

        foreach ( $this->ledger->earn_rows_for_order( (int) $order->get_id() ) as $earn ) {
            list( $original, $refunded ) = $this->bases( $order, $refunds, $earn );

            $owed       = ClawbackMath::owed( $earn->get_points(), $refunded, $original, $fully_refunded );
            $outcomes[] = $this->writer->refund_earn( $earn, $owed, ReferenceType::REFUND, $refund_id );
        }

        return $outcomes;
    }

    /**
     * The `refunded` status: a full reversal keyed on the order, but only when
     * no refund object exists — then the order was marked refunded outside
     * WooCommerce's refunds; otherwise the refund objects already drive it.
     *
     * @return Outcome[] One per earn row; none when refund objects exist.
     */
    public function refunded_status( \WC_Order $order ): array {
        return [] === $this->refunds( (int) $order->get_id() ) ? $this->full_order( $order ) : [];
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
     * The order's refunds as stored now. Not WC_Order::get_refunds() or its
     * refund totals: those are cached, and after a refund is deleted the
     * cache can still count it, which would read as fully refunded.
     *
     * @return \WC_Order_Refund[]
     */
    private function refunds( int $order_id ): array {
        return (array) wc_get_orders(
            [
                'type'   => 'shop_order_refund',
                'parent' => $order_id,
                'limit'  => -1,
            ]
        );
    }

    /**
     * The base an earn row was scored on and how much of it the order's
     * refunds removed, cumulatively. Only lines the earn's campaign counted
     * (BaseCalculator::includes_line()), so refunding an excluded product
     * claws back nothing. Refund lines point at the original through
     * `_refunded_item_id` and carry negative totals.
     *
     * @param \WC_Order_Refund[] $refunds
     * @return float[] [ original, refunded ]
     */
    private function bases( \WC_Order $order, array $refunds, LedgerEntry $earn ): array {
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

        foreach ( $refunds as $refund ) {
            foreach ( $refund->get_items() as $line ) {
                if ( isset( $included[ (int) $line->get_meta( '_refunded_item_id' ) ] ) ) {
                    $refunded += abs( (float) $line->get_total() );
                }
            }
        }

        return [ $original, $refunded ];
    }
}
