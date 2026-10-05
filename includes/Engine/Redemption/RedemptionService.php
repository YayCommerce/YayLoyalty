<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The two zones of redemption, one public method each. quote() is the
 * cart moment: the Quoter (gates, then the pure engine), nothing written,
 * run on every recalculation. commit() is the order moment: the same
 * Quoter again from the order under the lock, then one transaction, both
 * inside CommitWriter. Both adapters (classic, Store API) call only these;
 * no limit, eligibility rule or discount amount lives anywhere else.
 */
final class RedemptionService {

    public const REASON_NO_REWARD = 'no_reward';
    public const REASON_NO_INTENT = 'no_intent';

    /** The pure half; the same instance CommitWriter re-quotes with. */
    private Quoter $quoter;

    /** Reads WooCommerce and the customer row; never writes. */
    private ContextFactory $contexts;

    /** Read-only use: the built-in `cart-discount` reward. */
    private RewardRepository $rewards;

    private SessionIntent $intent;

    /** The only collaborator that can write; quote() and checkout_error() never touch it. */
    private CommitWriter $writer;

    public function __construct(
        Quoter $quoter,
        ContextFactory $contexts,
        RewardRepository $rewards,
        SessionIntent $intent,
        CommitWriter $writer
    ) {
        $this->quoter   = $quoter;
        $this->contexts = $contexts;
        $this->rewards  = $rewards;
        $this->intent   = $intent;
        $this->writer   = $writer;
    }

    /** Whether shoppers can spend points at checkout; the launcher and the points earned email show money values only when it is true. */
    public static function is_available(): bool {
        // TODO(redemption): return true once checkout redeems the reward.
        return false;
    }

    /**
     * Composition root: one Quoter (the engine with the build's handlers;
     * gates ProgramGate, RewardGate, then whatever
     * `yayloyalty_redemption_gates` appends for Pro) shared with the writer,
     * the read-only context factory, and the writer — LedgerService's
     * fifth construction site. Adapters and the coupon provider are
     * registered by the caller (YayLoyalty::__construct()), not here.
     */
    public static function build(): self {
        // TODO(redemption): build the Quoter from the Lite registry's RewardEngine and the gate list (ProgramGate, RewardGate, plus the yayloyalty_redemption_gates filter); the ContextFactory over a CustomerRepository; a CommitWriter over a new LedgerService, the redemption and customer repositories, the same factory and the same Quoter; return the service with the RewardRepository and a SessionIntent.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * ZONE 1. Pure given the cart and the session intent: writes nothing,
     * may run any number of times, and returns the same Result for the same
     * cart, balance and intent. Called by the coupon provider on every
     * recalculation and by both adapters to render the control.
     *
     * Preconditions: $cart's totals reflect every coupon but ours.
     * Postconditions: Result::denied( REASON_NO_REWARD ) when no flexible
     * reward is seeded; otherwise the Quoter's answer for
     * SessionIntent::get() — a gate reason, or Clamp's quote with
     * get_points() ≤ every ceiling and get_bound_by() naming the one that
     * bit. Nothing in the session changes.
     */
    public function quote( \WC_Cart $cart, ?int $user_id ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): look up the flexible reward (denied REASON_NO_REWARD when absent), build the Context from the cart, the user and the session intent, and return the Quoter's answer for them.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Checkout-submission affordability, before totals freeze. Precondition:
     * called from the adapter's validation hook with the live cart.
     * Postconditions: null when the intent is 0 or quote() applies every
     * requested point; otherwise a customer-facing message key
     * (the quote's reason or its bound_by) so the adapter can reject the
     * checkout with it. Writes nothing.
     */
    public function checkout_error( \WC_Cart $cart, ?int $user_id ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): intent 0 → null; quote(); denied → reason; points < points_requested → bound_by; else null.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * ZONE 2. Once per order, from the order-processed hook of either
     * adapter. Re-quotes from the order (never from the session), then
     * commits through CommitWriter in one transaction and clears the
     * session intent.
     *
     * Preconditions: $order was created from a cart carrying our coupon
     * line, or carrying none (then nothing to do). Totals are frozen.
     * Postconditions: Outcome::redeemed and the order carries
     * `_yayloyalty_points_redeemed` / `_yayloyalty_redeem_value`;
     * Outcome::skipped( reason ) with an order note and NO debit when a gate
     * or the locked balance says no — the order stands as created, never
     * half-written, never retried; Outcome::duplicate when this order was
     * already committed. The session intent is cleared in every case.
     * `yayloyalty_points_redeemed` ( Outcome, \WC_Order ) fires after
     * commit, only for redeemed.
     *
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function commit( \WC_Order $order ): Outcome { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): no coupon line → skipped( no_intent ); reward = flexible_reward() or skipped( no_reward ); outcome = writer->commit( order, reward ); intent->clear(); redeemed → do_action( yayloyalty_points_redeemed, outcome, order ); return outcome.
        throw new \RuntimeException( 'Not implemented' );
    }

    /** The seeded flexible reward (`cart-discount`); null when a merchant deleted it. Catalog rewards are the Pro seam, not this. */
    private function flexible_reward(): ?Reward {
        // TODO(redemption): look the reward up by the cart-discount slug through the repository and return it, null when absent.
        throw new \RuntimeException( 'Not implemented' );
    }
}
