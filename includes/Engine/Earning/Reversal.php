<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\BaseCalculator;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\ContextLineItem;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Takes earned points back: in full when an order is cancelled or a paid review is removed,
 * in proportion per refund, and restored when a refund is deleted. Works per earn row, so each
 * trigger is reversed against its own campaign. There is no re-award after a reversal: the earn key is spent.
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

    /** Composition root and a listed LedgerService construction site. */
    public static function build(): self {
        $ledger    = new LedgerRepository();
        $campaigns = new CampaignRepository();

        return new self( new LedgerService( $ledger, new CustomerRepository(), $campaigns ), $ledger, $campaigns );
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
        list( $refunds, , $is_fully_refunded ) = self::refund_state( $order );

        $lines            = self::product_lines( $order );
        $refunded_by_line = self::refunded_by_line( $refunds );
        $outcomes         = [];

        foreach ( $this->ledger->earn_rows_for_order( (int) $order->get_id() ) as $earn ) {
            list( $original, $refunded ) = $this->bases( $lines, $refunded_by_line, $earn );

            $owed       = ClawbackMath::owed( $earn->get_points(), $refunded, $original, $is_fully_refunded );
            $outcomes[] = $this->writer->refund_earn( $earn, $owed, ReferenceType::REFUND, $refund_id );
        }

        return $outcomes;
    }

    /**
     * The `refunded` status. Reverses in full, keyed on the order, only when no refund object exists:
     * then the order was marked refunded outside WooCommerce's refunds. Otherwise the refunds drive it.
     *
     * @return Outcome[] One per earn row; none when refund objects exist.
     */
    public function refunded_status( \WC_Order $order ): array {
        return [] === self::refunds( (int) $order->get_id(), 1 ) ? $this->full_order( $order ) : [];
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
     * Shared with Engine\Redemption\Reversal.
     *
     * @param int $limit -1 for all; 1 when only whether any exists matters.
     * @return \WC_Order_Refund[]
     */
    public static function refunds( int $order_id, int $limit = -1 ): array {
        return (array) wc_get_orders(
            [
                'type'   => 'shop_order_refund',
                'parent' => $order_id,
                'limit'  => $limit,
            ]
        );
    }

    /**
     * The order's refunds, what they add up to, and whether they leave nothing to refund.
     * Shared with Engine\Redemption\Reversal, which prorates by the amount.
     *
     * @return array{0: \WC_Order_Refund[], 1: float, 2: bool} [ refunds, refunded total, is fully refunded ]
     */
    public static function refund_state( \WC_Order $order ): array {
        $refunds        = self::refunds( (int) $order->get_id() );
        $refunded_total = array_sum( array_map( static fn( \WC_Order_Refund $refund ): float => (float) $refund->get_amount(), $refunds ) );
        $decimals       = wc_get_price_decimals();
        // Rounded like WooCommerce's own remaining amount: 0.35 + 0.10 is not >= 0.45 in floats.
        $is_fully_refunded = round( $refunded_total, $decimals ) >= round( (float) $order->get_total(), $decimals );

        return [ $refunds, (float) $refunded_total, $is_fully_refunded ];
    }

    /**
     * The order's product lines with the campaign-facing view of each.
     *
     * @return array<int, array{0: \WC_Order_Item_Product, 1: ContextLineItem}> item id => [ item, ContextFactory::line_item() ]
     */
    private static function product_lines( \WC_Order $order ): array {
        $lines = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( $item instanceof \WC_Order_Item_Product ) {
                $lines[ (int) $item_id ] = [ $item, ContextFactory::line_item( $item ) ];
            }
        }

        return $lines;
    }

    /**
     * Amount refunded per original line, across every refund. Refund lines
     * point at the original through `_refunded_item_id` and carry negative totals.
     *
     * @param \WC_Order_Refund[] $refunds
     * @return array<int, float> item id => refunded amount
     */
    private static function refunded_by_line( array $refunds ): array {
        $refunded = [];

        foreach ( $refunds as $refund ) {
            foreach ( $refund->get_items() as $line ) {
                $item_id              = (int) $line->get_meta( '_refunded_item_id' );
                $refunded[ $item_id ] = ( $refunded[ $item_id ] ?? 0.0 ) + abs( (float) $line->get_total() );
            }
        }

        return $refunded;
    }

    /**
     * The base an earn row was scored on and how much of it the order's
     * refunds removed, cumulatively. Only lines the earn's campaign counted
     * (BaseCalculator::includes_line()), so refunding an excluded product
     * claws back nothing.
     *
     * @param array<int, array{0: \WC_Order_Item_Product, 1: ContextLineItem}> $lines            product_lines()
     * @param array<int, float>                                         $refunded_by_line refunded_by_line()
     * @return float[] [ original, refunded ]
     */
    private function bases( array $lines, array $refunded_by_line, LedgerEntry $earn ): array {
        $campaign = null === $earn->get_campaign_id() ? null : $this->campaigns->find_campaign( (int) $earn->get_campaign_id() );

        // A recovery row has no campaign by design: every line counts, and nothing is gone.
        $is_recovery = null === $earn->get_campaign_id() && IdempotencyKey::RECOVERY === IdempotencyKey::qualifier_of( (string) $earn->get_idempotency_key() );

        if ( null === $campaign && ! $is_recovery ) {
            Log::warning( 'Reversal', 'campaign of ledger row #' . $earn->get_id() . ' is gone; every line counts toward its refund share' );
        }

        $original = 0.0;
        $refunded = 0.0;

        foreach ( $lines as $item_id => list( $item, $line_item ) ) {
            if ( null === $campaign || BaseCalculator::includes_line( $campaign, $line_item ) ) {
                $original += (float) $item->get_total();
                $refunded += $refunded_by_line[ $item_id ] ?? 0.0;
            }
        }

        return [ $original, $refunded ];
    }
}
