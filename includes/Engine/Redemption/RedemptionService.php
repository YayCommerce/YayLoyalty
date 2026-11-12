<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

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

    /** Rewards: the selection costs more than the balance, or one of it can no longer be used. */
    public const REASON_REWARDS_BALANCE    = 'rewards_balance';
    public const REASON_REWARD_UNAVAILABLE = 'reward_unavailable';

    /** The pure half; the same instance CommitWriter re-quotes with. */
    private Quoter $quoter;

    /** Reads WooCommerce and the customer row; never writes. */
    private ContextFactory $contexts;

    /** Read-only use: the built-in `cart-discount` reward and the rewards picked or offered. */
    private RewardRepository $rewards;

    private SessionIntent $intent;

    /** The only collaborator that can write; quote() and checkout_error() never touch it. */
    private CommitWriter $writer;

    /** Not used here: built beside the writer so it shares its LedgerService, and handed out by reversal(). */
    private Reversal $reversal;

    /** quote()'s copy of the flexible reward for this request. commit() reads the row fresh. */
    private ?Reward $quoted_reward = null;

    private bool $is_reward_read = false;

    /** The Quoter's handlers: which reward types this build can spend, and how a coupon reward is described. */
    private Registry $registry;

    /** @var array<int, Reward|null> Rewards read by id this request; null for an id with no row. */
    private array $reward_cache = [];

    /** @var array<string, Reward|null> Rewards read by coupon code this request; null for a code with no row. */
    private array $code_cache = [];

    /** quote_rewards()'s last answer, and the picked ids, shopper, cart and merchant coupons it was computed for. */
    private array $rewards_quoted = [];

    private string $rewards_quoted_for = '';

    public function __construct(
        Quoter $quoter,
        ContextFactory $contexts,
        RewardRepository $rewards,
        SessionIntent $intent,
        CommitWriter $writer,
        Reversal $reversal,
        Registry $registry
    ) {
        $this->quoter   = $quoter;
        $this->contexts = $contexts;
        $this->rewards  = $rewards;
        $this->intent   = $intent;
        $this->writer   = $writer;
        $this->reversal = $reversal;
        $this->registry = $registry;
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

        $registry    = Registry::with_lite_handlers();
        $quoter      = new Quoter( new RewardEngine( $registry ), $gates );
        $contexts    = new ContextFactory( $customers );
        $ledger      = new LedgerService( new LedgerRepository(), $customers, new CampaignRepository() );
        $redemptions = new RedemptionRepository();
        $rewards     = new RewardRepository();
        $writer      = new CommitWriter( $ledger, $redemptions, $customers, $contexts, $quoter );

        return new self( $quoter, $contexts, $rewards, $intent, $writer, new Reversal( $ledger, $redemptions, $customers, $rewards ), $registry );
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
     * SessionIntent::get(), or for everything (as ceiling()) when the reward
     * is auto max and the intent is positive — a gate reason, or Clamp's quote with
     * get_points() ≤ every ceiling and get_bound_by() naming the one that
     * bit. Nothing in the session changes.
     */
    public function quote( \WC_Cart $cart, ?int $user_id ): Result {
        $requested = $this->intent->get();
        $reward    = $this->quoted_reward();

        // Auto max is decided here, at cart time, never in Clamp: the commit re-quotes the order line's own points.
        if ( $requested > 0 && null !== $reward && Clamp::is_auto_max( $reward ) ) {
            $requested = PHP_INT_MAX;
        }

        return $this->quote_for( $cart, $user_id, $requested );
    }

    /**
     * The quote for asking for everything, whatever the session holds: get_points() is the most this shopper can
     * spend on this cart, get_value() what that buys, get_reason() why nobody can. The same path as quote()
     * (the request's reward, the gates, the engine), so the two cannot disagree. Writes nothing.
     */
    public function ceiling( \WC_Cart $cart, ?int $user_id ): Result {
        return $this->quote_for( $cart, $user_id, PHP_INT_MAX );
    }

    /**
     * quote() and ceiling(): the Quoter's answer for $points_requested, or Result::denied( REASON_NO_REWARD ).
     * Picked rewards are paid for first, so the flexible discount quotes against the balance less their cost and the cart
     * less their value; with none picked, or none allowed, it quotes against the whole of both.
     */
    private function quote_for( \WC_Cart $cart, ?int $user_id, int $points_requested ): Result {
        $reward = $this->quoted_reward();

        if ( null === $reward ) {
            return Result::denied( self::REASON_NO_REWARD );
        }

        $ctx    = $this->contexts->from_cart( $cart, $user_id, $points_requested );
        $cost   = 0;
        $amount = 0.0;

        foreach ( $this->quote_rewards( $cart, $user_id ) as $row ) {
            if ( self::applies( $row['quote'] ) ) {
                $cost   += $row['quote']->get_points();
                $amount += $row['quote']->get_value();
            }
        }

        if ( $cost > 0 ) {
            $ctx = $ctx->with_balance( $ctx->get_balance() - $cost )->with_discountable_amount( max( 0.0, (float) $ctx->get_discountable_amount() - $amount ) );
        }

        return $this->quoter->quote( $reward, $ctx );
    }

    /**
     * The flexible reward while it is live now, null otherwise: the row quote() prices, so the card's header
     * worth reads nothing of its own.
     */
    public function live_flexible_reward(): ?Reward {
        $reward = $this->quoted_reward();

        return null !== $reward && $reward->is_live_at( Dates::now() ) ? $reward : null;
    }

    /** The flexible reward for this request, live or not. One request quotes many times; the row is read once. */
    private function quoted_reward(): ?Reward {
        if ( ! $this->is_reward_read ) {
            $this->quoted_reward  = $this->flexible_reward();
            $this->is_reward_read = true;
            $this->remember( $this->quoted_reward );
        }

        return $this->quoted_reward;
    }

    /** Whether $reward is the flexible discount's: the built-in `flexible_discount` reward, not a catalog one. */
    public static function is_flexible_discount( ?Reward $reward ): bool {
        return null !== $reward && RewardType::FLEXIBLE_DISCOUNT === $reward->get_type();
    }

    /** The flexible discount's coupon code: the flexible reward's stored code, '' when that reward is missing. */
    public function flexible_discount_code(): string {
        $reward = $this->quoted_reward();

        return null === $reward ? '' : $reward->get_coupon_code();
    }

    /** Whether a reward's quote puts a line on the cart: allowed, with a value. */
    public static function applies( Result $quote ): bool {
        return $quote->is_allowed() && $quote->get_value() > 0;
    }

    /**
     * The catalog rewards whose coupon is on the cart, keyed by code in the order the cart holds them. Codes of a
     * missing reward, and the flexible discount's, are left out.
     *
     * @return array<string, Reward>
     */
    public function applied_rewards( \WC_Cart $cart ): array {
        $rewards = [];

        foreach ( $cart->get_applied_coupons() as $code ) {
            $reward = $this->reward_for_code( (string) $code );

            if ( null !== $reward && ! self::is_flexible_discount( $reward ) ) {
                $rewards[ (string) $code ] = $reward;
            }
        }

        return $rewards;
    }

    /**
     * The applied rewards' costs, keyed by reward id: picked ones whose coupon is on the cart. The card's
     * summary and Pro's reward rows both read it.
     *
     * @return array<int, int>
     */
    public function applied_reward_costs( \WC_Cart $cart, ?int $user_id ): array {
        $on_cart = array_map( 'strval', $cart->get_applied_coupons() );
        $costs   = [];

        foreach ( $this->quote_rewards( $cart, $user_id ) as $reward_id => $row ) {
            if ( null !== $row['reward'] && in_array( $row['reward']->get_coupon_code(), $on_cart, true ) ) {
                $costs[ $reward_id ] = (int) $row['reward']->get_cost_points();
            }
        }

        return $costs;
    }

    /**
     * The picked rewards' quotes, keyed by reward id in the order they were picked. Each quotes against the
     * balance left once the rewards allowed before it are paid for, so a selection never costs more than the
     * balance. A missing reward, or one this build does not offer, is denied `not_live` without reaching the
     * Quoter, so a type with no handler (Lite after a downgrade) never throws. Writes nothing. Every flexible discount
     * quote asks for this, many times a request, so the answer is kept while the picked ids, the shopper and
     * cart_key() stay the same.
     *
     * @return array<int, array{reward: ?Reward, quote: Result}>
     */
    public function quote_rewards( \WC_Cart $cart, ?int $user_id ): array {
        $reward_ids = $this->intent->rewards();

        if ( [] === $reward_ids ) {
            return [];
        }

        $key = implode( ',', $reward_ids ) . '|' . (int) $user_id . '|' . self::cart_key( $cart );

        if ( $key !== $this->rewards_quoted_for ) {
            $this->rewards_quoted     = $this->quote_picked( $cart, $user_id, $reward_ids );
            $this->rewards_quoted_for = $key;
        }

        return $this->rewards_quoted;
    }

    /**
     * What a reward's quote reads from the cart: each item's key, quantity and price, and the merchant coupons.
     * Not WC_Cart::get_cart_hash(), which folds in the total and so changes while the totals run.
     */
    private static function cart_key( \WC_Cart $cart ): string {
        $parts = [];

        foreach ( $cart->get_cart() as $item_key => $item ) {
            $price   = isset( $item['data'] ) && $item['data'] instanceof \WC_Product ? $item['data']->get_price() : '';
            $parts[] = $item_key . ':' . ( $item['quantity'] ?? 0 ) . ':' . $price;
        }

        foreach ( $cart->get_applied_coupons() as $code ) {
            if ( ! EarnContext::is_loyalty_code( (string) $code ) ) {
                $parts[] = (string) $code;
            }
        }

        return implode( ',', $parts );
    }

    /**
     * quote_rewards() without the memo.
     *
     * @param int[] $reward_ids
     * @return array<int, array{reward: ?Reward, quote: Result}>
     */
    private function quote_picked( \WC_Cart $cart, ?int $user_id, array $reward_ids ): array {
        $ctx    = $this->contexts->from_cart( $cart, $user_id, null );
        $spent  = 0;
        $quotes = [];

        foreach ( $reward_ids as $reward_id ) {
            $reward = $this->find_reward( $reward_id );

            if ( null === $reward || ! $this->is_reward_offered( $reward ) ) {
                $quotes[ $reward_id ] = [
                    'reward' => $reward,
                    'quote'  => Result::denied( Result::REASON_NOT_LIVE ),
                ];

                continue;
            }

            $quote = $this->quoter->quote( $reward, $ctx->with_balance( $ctx->get_balance() - $spent ) );

            if ( $quote->is_allowed() ) {
                $spent += $quote->get_points();
            }

            $quotes[ $reward_id ] = [
                'reward' => $reward,
                'quote'  => $quote,
            ];
        }

        return $quotes;
    }

    /**
     * Every coupon reward this build offers now, keyed by reward id in `sort_order`, each quoted alone against
     * the full balance: what the Rewards panel lists. Writes nothing.
     *
     * @return array<int, array{reward: Reward, quote: Result}>
     */
    public function reward_options( \WC_Cart $cart, ?int $user_id ): array {
        $options = [];
        $ctx     = null;

        foreach ( $this->rewards->live_of_types( [ RewardType::FIXED_COUPON, RewardType::PERCENT_COUPON ], Dates::now() ) as $reward ) {
            if ( ! $this->is_reward_offered( $reward ) ) {
                continue;
            }

            $ctx = $ctx ?? $this->contexts->from_cart( $cart, $user_id, null );

            $options[ (int) $reward->get_id() ] = [
                'reward' => $reward,
                'quote'  => $this->quoter->quote( $reward, $ctx ),
            ];
        }

        return $options;
    }

    /**
     * The reward whose stored coupon code is $code, read once per request; null for a code that is not a loyalty
     * code (without a query) or a missing row.
     */
    public function reward_for_code( string $code ): ?Reward {
        if ( ! EarnContext::is_loyalty_code( $code ) ) {
            return null;
        }

        if ( ! array_key_exists( $code, $this->code_cache ) ) {
            $this->remember( $this->rewards->find_by_coupon_code( $code ) );
            $this->code_cache[ $code ] = $this->code_cache[ $code ] ?? null;
        }

        return $this->code_cache[ $code ];
    }

    /**
     * The `woocommerce_get_shop_coupon_data` array for the reward's virtual coupon, capped against $cart when
     * given. Null when no handler spends its type as a coupon.
     *
     * @return array<string, mixed>|null
     */
    public function coupon_data_for( Reward $reward, ?\WC_Cart $cart ): ?array {
        $props = $this->coupon_props_of( $reward );

        if ( null === $props ) {
            return null;
        }

        return RewardCoupons::data( null === $cart ? $props : RewardCoupons::capped( $reward->get_coupon_code(), $props, $cart ) );
    }

    /** Whether the card may offer the reward: live now, not the flexible discount's, and spent as a coupon by a registered handler. */
    public function is_reward_offered( Reward $reward ): bool {
        return ! self::is_flexible_discount( $reward )
            && $reward->is_live_at( Dates::now() )
            && null !== $this->coupon_props_of( $reward );
    }

    /**
     * Checkout-submission affordability, before totals freeze. Precondition:
     * called from the adapter's validation hook with the live cart.
     * Postconditions: picked rewards first, rewards_error() when it has a
     * key. Then the flexible discount: null when nothing is asked, when the quote
     * applies any points, even fewer than asked (the cart already shows the
     * clamped discount), or when it applies none beside a reward that
     * applies (the rewards took what it could have had, and it comes back if
     * they go). Otherwise a message key for message_for(): the quote's reason
     * when it is denied, or its bound_by when it applies nothing. Writes nothing.
     */
    public function checkout_error( \WC_Cart $cart, ?int $user_id ): ?string {
        $error = $this->rewards_error( $cart, $user_id );

        if ( null !== $error || 0 === $this->intent->get() ) {
            return $error;
        }

        $quote = $this->quote( $cart, $user_id );

        if ( ! $quote->is_allowed() ) {
            return $quote->get_reason();
        }

        if ( $quote->get_points() > 0 || $this->has_applying_reward( $cart, $user_id ) ) {
            return null;
        }

        return '' !== $quote->get_bound_by() ? $quote->get_bound_by() : Clamp::BOUND_REWARD_MIN;
    }

    /** Whether a picked reward's quote is allowed with a value: one that puts a line on the cart. */
    private function has_applying_reward( \WC_Cart $cart, ?int $user_id ): bool {
        foreach ( $this->quote_rewards( $cart, $user_id ) as $row ) {
            if ( self::applies( $row['quote'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Picked rewards at checkout: REASON_REWARD_UNAVAILABLE when one is missing, denied for anything but the
     * balance, or worth nothing here; else REASON_REWARDS_BALANCE when the balance cannot cover one; else null.
     */
    private function rewards_error( \WC_Cart $cart, ?int $user_id ): ?string {
        $is_short = false;

        foreach ( $this->quote_rewards( $cart, $user_id ) as $row ) {
            $quote = $row['quote'];

            if ( Result::REASON_INSUFFICIENT_BALANCE === $quote->get_reason() ) {
                $is_short = true;
            } elseif ( null === $row['reward'] || ! self::applies( $quote ) ) {
                return self::REASON_REWARD_UNAVAILABLE;
            }
        }

        return $is_short ? self::REASON_REWARDS_BALANCE : null;
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

            case self::REASON_REWARDS_BALANCE:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( 'You do not have enough %s for these rewards. Remove one and try again.', 'yayloyalty' ), $label );

            case self::REASON_REWARD_UNAVAILABLE:
                return __( 'One of your rewards can no longer be used on this order. Review your rewards and try again.', 'yayloyalty' );

            case RewardCoupons::REASON_MIN_ORDER:
                // The caller fills in the amount still missing.
                /* translators: %s: money amount, as "$5". */
                return __( 'Add %s more to use this', 'yayloyalty' );

            case RewardCoupons::REASON_SALE_ITEMS:
                return __( 'Items on sale don\'t qualify', 'yayloyalty' );

            case RewardCoupons::REASON_NO_VALUE:
                return __( 'Nothing in your cart qualifies', 'yayloyalty' );
        }

        return __( 'Your points discount could not be applied. Review your order and try again.', 'yayloyalty' );
    }

    /**
     * ZONE 2. Once per order, from the order-processed hook of either
     * adapter. Re-quotes from the order (never from the session) and
     * commits through CommitWriter in one transaction: the flexible discount's line
     * through commit(), reward lines through commit_rewards(), and an order
     * carrying both kinds through commit_all().
     *
     * First, on an order checkout reused, every row whose line is gone gets its points back (settle_gone_lines()),
     * so the debit below sees them.
     *
     * Postconditions, exactly one of:
     * - The order has no coupon line of ours: Outcome::skipped( REASON_NO_INTENT ), nothing else happens.
     * - Outcome::redeemed: the order carries `_yayloyalty_points_redeemed` /
     *   `_yayloyalty_redeem_value`, and `yayloyalty_points_redeemed`
     *   ( Outcome, \WC_Order ) fires after commit.
     * - Outcome::duplicate: this order was already committed.
     * - Outcome::skipped( reason ): nothing was debited, so every loyalty
     *   coupon line is taken off the order — it must not stay payable with
     *   a discount and no debit — their rows get their points back, and the
     *   adapter fails the checkout.
     * The session intent is cleared in the last three.
     *
     * A commit that throws wrote nothing, so the order is treated as skipped: its loyalty lines come off and the
     * intent is cleared before the exception goes on, or the order would stay payable with a discount and no debit.
     *
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function commit( \WC_Order $order ): Outcome {
        $codes = EarnContext::loyalty_codes( $order->get_coupon_codes() );

        try {
            $outcome = $this->commit_lines( $order, $codes );
        } catch ( \Throwable $e ) {
            $this->intent->clear();
            $this->take_lines_off_after_failure( $order, $codes );

            throw $e;
        }

        if ( [] === $codes ) {
            return $outcome;
        }

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
            $this->take_lines_off( $order, $codes );
        }

        return $outcome;
    }

    /**
     * Settles the lines the order lost, then debits the ones it carries, through the writer call that fits them.
     *
     * @param string[] $codes The loyalty codes on the order.
     */
    private function commit_lines( \WC_Order $order, array $codes ): Outcome {
        $has_flexible_discount = false;
        $reward_codes          = [];

        $this->settle_gone_lines( $order, $codes );

        foreach ( $codes as $code ) {
            if ( self::is_flexible_discount( $this->reward_for_code( $code ) ) ) {
                $has_flexible_discount = true;
            } else {
                $reward_codes[] = $code;
            }
        }

        if ( ! $has_flexible_discount && [] === $reward_codes ) {
            return Outcome::skipped( self::REASON_NO_INTENT );
        }

        if ( $has_flexible_discount ) {
            // Read fresh: quote()'s copy may predate a merchant's edit in this request.
            $reward = $this->flexible_reward();

            if ( null === $reward ) {
                return Outcome::skipped( self::REASON_NO_REWARD );
            }

            return [] === $reward_codes
                ? $this->writer->commit( $order, $reward )
                : $this->writer->commit_all( $order, $this->offered_rewards_of( $reward_codes ), $reward );
        }

        return $this->writer->commit_rewards( $order, $this->offered_rewards_of( $reward_codes ) );
    }

    /**
     * Takes the loyalty lines off an order nothing was debited for, and gives back the rows they leave behind.
     *
     * @param string[] $codes The loyalty codes on the order.
     */
    private function take_lines_off( \WC_Order $order, array $codes ): void {
        foreach ( $codes as $code ) {
            $order->remove_coupon( $code );
        }

        $this->settle_gone_lines( $order, [] );
    }

    /**
     * take_lines_off() while a commit's exception is on its way out: a second failure is logged, not thrown, so that
     * exception still reaches the adapter. What is left of the order shows in the order screen's repair box.
     *
     * @param string[] $codes The loyalty codes on the order.
     */
    private function take_lines_off_after_failure( \WC_Order $order, array $codes ): void {
        try {
            $this->take_lines_off( $order, $codes );
        } catch ( \Throwable $e ) {
            Log::error( 'RedemptionService', sprintf( 'Taking the loyalty lines off order #%d failed: %s', (int) $order->get_id(), $e->getMessage() ) );
        }
    }

    /**
     * Gives back the points of every `applied` row whose line is not among $codes, notes them on the order and freezes
     * what is left. A reused order keeps the rows of its first attempt while WooCommerce rebuilds its lines from the cart.
     *
     * @param string[] $codes The loyalty codes on the order.
     */
    private function settle_gone_lines( \WC_Order $order, array $codes ): void {
        $points = 0;

        foreach ( $this->reversal->lines_gone( $order, $codes ) as $outcome ) {
            if ( Outcome::AWARDED === $outcome->get_status() ) {
                $points += $outcome->get_entry()->get_points();
            }
        }

        if ( $points > 0 ) {
            $this->writer->freeze( $order );
            /* translators: %s: points in the store's format, as "500 Points". */
            $order->add_order_note( sprintf( __( '%s restored: their discount is no longer on this order.', 'yayloyalty' ), SettingsHelper::points_text( $points ) ) );
        }
    }

    /**
     * Each reward code mapped to its reward, or to null when the reward is missing or no longer offered.
     *
     * @param string[] $codes
     * @return array<string, Reward|null>
     */
    private function offered_rewards_of( array $codes ): array {
        $rewards = [];

        foreach ( $codes as $code ) {
            $reward           = $this->reward_for_code( $code );
            $rewards[ $code ] = null !== $reward && $this->is_reward_offered( $reward ) ? $reward : null;
        }

        return $rewards;
    }

    /**
     * An admin's repair of one RewardMismatch::find() answer: returns a stale row's points, or debits an unpaid line.
     * A reward that is gone leaves the line with no price: REASON_REWARD_UNAVAILABLE, nothing written.
     *
     * @param array{kind: string, code: string, points: int, reward: ?Reward} $mismatch
     * @return Outcome awarded when points moved, else skipped or duplicate.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function repair_mismatch( \WC_Order $order, array $mismatch ): Outcome {
        if ( RewardMismatch::KIND_UNPAID_LINE === $mismatch['kind'] ) {
            return null === $mismatch['reward'] || $mismatch['points'] < 1
                ? Outcome::skipped( self::REASON_REWARD_UNAVAILABLE )
                : $this->writer->debit_line( $order, $mismatch['reward'], $mismatch['code'], $mismatch['points'] );
        }

        $outcome = $this->reversal->coupon_removed( $order, $mismatch['code'] ) ?? Outcome::skipped( Reversal::REASON_NOTHING_TO_MOVE );

        if ( Outcome::AWARDED === $outcome->get_status() ) {
            $this->writer->freeze( $order );
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
        return $this->rewards->find_by_slug( RewardRepository::FLEXIBLE_DISCOUNT_SLUG );
    }

    /** The reward by id, read once per request. */
    private function find_reward( int $reward_id ): ?Reward {
        if ( ! array_key_exists( $reward_id, $this->reward_cache ) ) {
            $this->remember( $this->rewards->find_reward( $reward_id ) );
            $this->reward_cache[ $reward_id ] = $this->reward_cache[ $reward_id ] ?? null;
        }

        return $this->reward_cache[ $reward_id ];
    }

    /** Files a reward read by id, by code or by slug under both its id and its code, so no path reads it again. */
    private function remember( ?Reward $reward ): void {
        if ( null !== $reward ) {
            $this->reward_cache[ (int) $reward->get_id() ]  = $reward;
            $this->code_cache[ $reward->get_coupon_code() ] = $reward;
        }
    }

    /**
     * The coupon the reward's handler describes, in the currency the request charges in; null for the flexible discount's
     * type, or a type with no handler (Lite).
     */
    private function coupon_props_of( Reward $reward ): ?array {
        $props = $this->registry->has( $reward->get_type() ) ? $this->registry->get( $reward->get_type() )->coupon_props( $reward ) : null;

        return null === $props ? null : RewardCoupons::in_currency( $props, CurrencyRate::rate( CurrencyRate::current() ) );
    }
}
