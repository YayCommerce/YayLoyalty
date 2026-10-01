<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Services\LedgerService;

defined( 'ABSPATH' ) || exit;

/**
 * ZONE 2's one transaction. Holds every collaborator that can write; the
 * Quoter holds none of them, so the quote path cannot reach a writer.
 * Locks the customer row, re-quotes from the order with the locked
 * balance through the same Quoter the cart used, then inserts the
 * redemptions row and the REDEEM ledger row (through LedgerService — the
 * one ledger writer) together, moves the aggregates and freezes the value
 * onto the order. Cancel and refund are not here: they attach as
 * `Engine\Redemption\Reversal` from Earning\Listeners\OrderReversalListener::on_cancelled()
 * / on_order_refunded(), needs LedgerService::refund_redeem() and
 * Redemption::restored_points, and is undesigned. Scaffold: stubs.
 */
final class CommitWriter {

    /** Order meta the value is frozen onto; projections, never read to compute a balance. */
    public const META_POINTS = '_yayloyalty_points_redeemed';
    public const META_VALUE  = '_yayloyalty_redeem_value';

    private LedgerService $ledger;

    private RedemptionRepository $redemptions;

    private CustomerRepository $customers;

    private ContextFactory $contexts;

    private Quoter $quoter;

    public function __construct(
        LedgerService $ledger,
        RedemptionRepository $redemptions,
        CustomerRepository $customers,
        ContextFactory $contexts,
        Quoter $quoter
    ) {
        $this->ledger      = $ledger;
        $this->redemptions = $redemptions;
        $this->customers   = $customers;
        $this->contexts    = $contexts;
        $this->quoter      = $quoter;
    }

    /**
     * Preconditions: $order is saved, its totals frozen, and it carries our
     * coupon line; $reward is the flexible reward. Called at most once per
     * hook firing, any number of firings.
     *
     * Postconditions, inside one Transaction::run(): ensure_row →
     * lock_and_get → find_by_order() already there → Outcome::duplicate,
     * nothing written; the Quoter's re-quote (gates, then Clamp with the
     * LOCKED balance and the order's after-coupon subtotal) denies or yields
     * fewer points than the coupon line froze → Outcome::skipped( reason ), nothing written, an order
     * note naming the reason — the order stands without the debit, never
     * half-written, never retried; otherwise the redemptions row (status
     * `applied`, value, rate_snapshot, coupon_code) then
     * LedgerService::redeem( draft ) then order meta, and Outcome::redeemed.
     * The balance never goes below zero: LedgerService::redeem() refuses
     * under the same lock. Nothing slow runs inside.
     *
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function commit( \WC_Order $order, Reward $reward ): Outcome { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): Transaction::run: ensure_row, customer = lock_and_get; redemptions->find_by_order( order id, reward id ) → duplicate; ctx = contexts->from_order( order, customer balance ); quote = quoter->quote( reward, ctx ), denied or points below the frozen points → order note + skipped; redemption_id = redemptions->insert( redemption_from( quote, order ) ); outcome = ledger->redeem( draft_from( quote, order, redemption_id ) ); redeemed → freeze( order, quote ); return outcome.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The redemptions row a quote becomes. Precondition: $quote allowed with
     * points > 0. Postcondition: user and order from $order, reward from the
     * quote, points/value/currency/rate_snapshot from the quote, base_value
     * via `yayloyalty_redemption_base_value` (identity until multi-currency),
     * coupon_code = Points\Context::REDEEM_COUPON_CODE, coupon_id null,
     * status Redemption::STATUS_APPLIED, restored_points 0.
     */
    private function redemption_from( Result $quote, \WC_Order $order ): Redemption { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): construct the Redemption exactly as the docblock lists its fields, created_at and updated_at = Dates::now().
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The REDEEM row. Precondition: the redemptions row is inserted (its id is
     * the reference). Postcondition: type REDEEM, source
     * LedgerSource::ORDER (the vocabulary has no redemption source; the reference does that), points = −quote points, reference_type
     * REDEMPTION, reference_id and redemption_id = $redemption_id, order_id
     * from $order, actor_id null, balance_after 0 (computed under the lock),
     * created_at = Dates::now(), idempotency_key = IdempotencyKey::compose(
     * REDEEM, user_id, REDEMPTION, $redemption_id, reward type ).
     */
    private function draft_from( Result $quote, \WC_Order $order, int $redemption_id ): LedgerEntry { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): build the LedgerEntry with every field exactly as the docblock above lists them.
        throw new \RuntimeException( 'Not implemented' );
    }

    /** Freezes points and value onto the order (HPOS-safe update_meta_data + save) so the order screen and emails never re-derive them. */
    private function freeze( \WC_Order $order, Result $quote ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): write META_POINTS from the quote's points and META_VALUE from its value formatted as a WooCommerce decimal onto the order, then save its meta.
        throw new \RuntimeException( 'Not implemented' );
    }
}
