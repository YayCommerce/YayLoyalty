<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\LedgerService;

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

    /** Not used here: built beside the writer so it shares its LedgerService, and handed out by reversal(). */
    private Reversal $reversal;

    /** quote()'s copy of the flexible reward for this request. commit() reads the row fresh. */
    private ?Reward $quoted_reward = null;

    private bool $is_reward_read = false;

    public function __construct(
        Quoter $quoter,
        ContextFactory $contexts,
        RewardRepository $rewards,
        SessionIntent $intent,
        CommitWriter $writer,
        Reversal $reversal
    ) {
        $this->quoter   = $quoter;
        $this->contexts = $contexts;
        $this->rewards  = $rewards;
        $this->intent   = $intent;
        $this->writer   = $writer;
        $this->reversal = $reversal;
    }

    /**
     * Composition root: one Quoter (the engine with the build's handlers;
     * gates ProgramGate, RewardGate, then whatever
     * `yayloyalty_redemption_gates` appends for Pro) shared with the writer,
     * the read-only context factory, and the writer and the reversal, which
     * share the one LedgerService built here, a listed construction site. Adapters and the coupon provider are
     * registered by the caller (YayLoyalty::__construct()), not here, with
     * the same $intent this is given.
     */
    public static function build( SessionIntent $intent ): self {
        $customers = new CustomerRepository();

        /**
         * The gates a redemption passes before it is quoted and again before it is debited, in check order.
         * Internal seam for Pro, not a public API.
         *
         * @param Gate[] $gates
         */
        $gates = (array) apply_filters( 'yayloyalty_redemption_gates', [ new ProgramGate(), new RewardGate() ] );

        $quoter      = new Quoter( new RewardEngine( Registry::with_lite_handlers() ), $gates );
        $contexts    = new ContextFactory( $customers );
        $ledger      = new LedgerService( new LedgerRepository(), $customers, new CampaignRepository() );
        $redemptions = new RedemptionRepository();
        $rewards     = new RewardRepository();
        $writer      = new CommitWriter( $ledger, $redemptions, $customers, $contexts, $quoter );

        return new self( $quoter, $contexts, $rewards, $intent, $writer, new Reversal( $ledger, $redemptions, $customers, $rewards ) );
    }

    /** Handed to Earning\Listeners\OrderReversalListener, so reversals write through this root's LedgerService and no construction site is added. */
    public function reversal(): Reversal {
        return $this->reversal;
    }

    /** The signed-in shopper's id, null for a guest: the `$user_id` quote() and checkout_error() take. */
    public static function shopper_id(): ?int {
        $user_id = get_current_user_id();

        return $user_id > 0 ? $user_id : null;
    }

    /** The shopper's cart, null on a request that has none (wp-admin, cron, most REST). */
    public static function cart(): ?\WC_Cart {
        $cart = function_exists( 'WC' ) ? WC()->cart : null;

        return $cart instanceof \WC_Cart ? $cart : null;
    }

    /**
     * ZONE 1. Pure given the cart and the session intent: writes nothing,
     * may run any number of times, and returns the same Result for the same
     * cart, balance and intent. Called by the coupon provider on every
     * recalculation and by both adapters to render the control.
     *
     * Postconditions: Result::denied( REASON_NO_REWARD ) when no flexible
     * reward is seeded; otherwise the Quoter's answer for
     * SessionIntent::get() — a gate reason, or Clamp's quote with
     * get_points() ≤ every ceiling and get_bound_by() naming the one that
     * bit. Nothing in the session changes.
     */
    public function quote( \WC_Cart $cart, ?int $user_id ): Result {
        return $this->quote_for( $cart, $user_id, $this->intent->get() );
    }

    /**
     * The quote for asking for everything, whatever the session holds: get_points() is the most this shopper can
     * spend on this cart, get_value() what that buys, get_reason() why nobody can. The same path as quote()
     * (the request's reward, the gates, the engine), so the two cannot disagree. Writes nothing.
     */
    public function ceiling( \WC_Cart $cart, ?int $user_id ): Result {
        return $this->quote_for( $cart, $user_id, PHP_INT_MAX );
    }

    /** quote() and ceiling(): the Quoter's answer for $points_requested, or Result::denied( REASON_NO_REWARD ). */
    private function quote_for( \WC_Cart $cart, ?int $user_id, int $points_requested ): Result {
        // One request quotes many times (every coupon load and totals run); the reward row is read for the first only.
        if ( ! $this->is_reward_read ) {
            $this->quoted_reward  = $this->flexible_reward();
            $this->is_reward_read = true;
        }

        $reward = $this->quoted_reward;

        if ( null === $reward ) {
            return Result::denied( self::REASON_NO_REWARD );
        }

        return $this->quoter->quote( $reward, $this->contexts->from_cart( $cart, $user_id, $points_requested ) );
    }

    /**
     * Checkout-submission affordability, before totals freeze. Precondition:
     * called from the adapter's validation hook with the live cart.
     * Postconditions: null when the intent is 0, and null when the quote
     * applies any points, even fewer than asked: the cart already shows the
     * clamped discount. Otherwise a message key for message_for(): the
     * quote's reason when it is denied, or its bound_by when it applies
     * nothing. Writes nothing.
     */
    public function checkout_error( \WC_Cart $cart, ?int $user_id ): ?string {
        if ( 0 === $this->intent->get() ) {
            return null;
        }

        $quote = $this->quote( $cart, $user_id );

        if ( ! $quote->is_allowed() ) {
            return $quote->get_reason();
        }

        if ( $quote->get_points() > 0 ) {
            return null;
        }

        return '' !== $quote->get_bound_by() ? $quote->get_bound_by() : Clamp::BOUND_REWARD_MIN;
    }

    /** The shopper-facing sentence for a denial reason or a clamp bound, with the merchant's points label. */
    public static function message_for( string $key ): string {
        $label = SettingsHelper::points_label_plural();

        switch ( $key ) {
            case ProgramGate::REASON_NO_CUSTOMER:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( 'Sign in to spend your %s.', 'yayloyalty' ), $label );

            case ProgramGate::REASON_PROGRAM_PAUSED:
            case ProgramGate::REASON_COUPONS_DISABLED:
            case Result::REASON_NOT_LIVE:
            case Result::REASON_NO_RATE:
            case self::REASON_NO_REWARD:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s cannot be spent on this store right now.', 'yayloyalty' ), $label );

            case ProgramGate::REASON_OPTED_OUT:
            case ProgramGate::REASON_EXCLUDED_ROLE:
            case Result::REASON_CONDITIONS_NOT_MET:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( 'Your account cannot spend %s on this order.', 'yayloyalty' ), $label );

            case Result::REASON_INSUFFICIENT_BALANCE:
            case Clamp::BOUND_BALANCE:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( 'You do not have enough %s for this discount. Choose a lower amount and try again.', 'yayloyalty' ), $label );

            case Clamp::BOUND_REWARD_MIN:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( 'That is below the minimum number of %s you can spend.', 'yayloyalty' ), $label );

            case Clamp::BOUND_REWARD_MAX:
                return __( 'That is more than one order can take, so the discount was lowered.', 'yayloyalty' );

            case Clamp::BOUND_CART:
                return __( 'The discount cannot be more than this order allows, so it was lowered.', 'yayloyalty' );

            case Clamp::BOUND_STEP:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( '%s are spent in fixed steps, so the amount was rounded down.', 'yayloyalty' ), $label );
        }

        return __( 'Your points discount could not be applied. Review your order and try again.', 'yayloyalty' );
    }

    /**
     * ZONE 2. Once per order, from the order-processed hook of either
     * adapter. Re-quotes from the order (never from the session) and
     * commits through CommitWriter in one transaction.
     *
     * Postconditions, exactly one of:
     * - The order has no coupon line of ours: Outcome::skipped( REASON_NO_INTENT ), nothing else happens.
     * - Outcome::redeemed: the order carries `_yayloyalty_points_redeemed` /
     *   `_yayloyalty_redeem_value`, and `yayloyalty_points_redeemed`
     *   ( Outcome, \WC_Order ) fires after commit.
     * - Outcome::duplicate: this order was already committed.
     * - Outcome::skipped( reason ): nothing was debited, so our coupon line
     *   is taken off the order — it must not stay payable with a discount
     *   and no debit — and the adapter fails the checkout.
     * The session intent is cleared in the last three.
     *
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function commit( \WC_Order $order ): Outcome {
        if ( ! in_array( CouponProvider::CODE, $order->get_coupon_codes(), true ) ) {
            return Outcome::skipped( self::REASON_NO_INTENT );
        }

        $reward  = $this->flexible_reward();
        $outcome = null === $reward ? Outcome::skipped( self::REASON_NO_REWARD ) : $this->writer->commit( $order, $reward );

        $this->intent->clear();

        if ( Outcome::REDEEMED === $outcome->get_status() ) {
            Transaction::after_commit(
                static function () use ( $outcome, $order ): void {
                    /**
                     * Fires after a redemption commits, once per order.
                     *
                     * @param Outcome   $outcome Carries the REDEEM row and the quote it was written from.
                     * @param \WC_Order $order
                     */
                    do_action( 'yayloyalty_points_redeemed', $outcome, $order );
                }
            );
        }

        if ( Outcome::SKIPPED === $outcome->get_status() ) {
            $order->remove_coupon( CouponProvider::CODE );
        }

        return $outcome;
    }

    /**
     * Whether a commit() outcome must stop the checkout: a message key for message_for() when nothing was
     * debited for a reason other than "no points discount on this order", null otherwise. Both adapters ask
     * here and only choose how to fail.
     */
    public static function commit_error( Outcome $outcome ): ?string {
        if ( Outcome::SKIPPED !== $outcome->get_status() || self::REASON_NO_INTENT === $outcome->get_reason() ) {
            return null;
        }

        return $outcome->get_reason();
    }

    /**
     * The seeded flexible reward (`cart-discount`), live or not: liveness is RewardGate's answer, so the shopper
     * is told `not_live` rather than `no_reward`. Null when a merchant deleted it. Catalog rewards are the Pro seam, not this.
     */
    private function flexible_reward(): ?Reward {
        return $this->rewards->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG );
    }
}
