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
use YayLoyalty\Utils\Dates;

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

    /** The card's exclusivity notices: rewards block the slider, the slider blocks rewards. */
    public const NOTICE_EXCLUSIVE_SLIDER  = 'exclusive_slider';
    public const NOTICE_EXCLUSIVE_REWARDS = 'exclusive_rewards';

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
     * The picked rewards' quotes, keyed by reward id in the order they were picked. Each quotes against the
     * balance left once the rewards allowed before it are paid for, so a selection never costs more than the
     * balance. A missing reward, or one this build does not offer, is denied `not_live` without reaching the
     * Quoter, so a type with no handler (Lite after a downgrade) never throws. Writes nothing.
     *
     * @return array<int, array{reward: ?Reward, quote: Result}>
     */
    public function quote_rewards( \WC_Cart $cart, ?int $user_id ): array {
        $reward_ids = $this->intent->rewards();

        if ( [] === $reward_ids ) {
            return [];
        }

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

    /** The reward a `yayloyalty-reward-{id}` code names, read once per request; null for any other code or a missing row. */
    public function reward_for_code( string $code ): ?Reward {
        $reward_id = CouponProvider::reward_id_of( $code );

        return null === $reward_id ? null : $this->find_reward( $reward_id );
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

        return RewardCoupons::data( null === $cart ? $props : RewardCoupons::capped( (int) $reward->get_id(), $props, $cart ) );
    }

    /** Whether the card may offer the reward: live now, not the slider's, and spent as a coupon by a registered handler. */
    public function is_reward_offered( Reward $reward ): bool {
        return RewardType::FLEXIBLE_DISCOUNT !== $reward->get_type()
            && $reward->is_live_at( Dates::now() )
            && null !== $this->coupon_props_of( $reward );
    }

    /**
     * Checkout-submission affordability, before totals freeze. Precondition:
     * called from the adapter's validation hook with the live cart.
     * Postconditions: null when nothing is asked, and null when the quote
     * applies any points, even fewer than asked: the cart already shows the
     * clamped discount. Otherwise a message key for message_for(): the
     * quote's reason when it is denied, or its bound_by when it applies
     * nothing. For picked rewards, rewards_error(). Writes nothing.
     */
    public function checkout_error( \WC_Cart $cart, ?int $user_id ): ?string {
        if ( 0 === $this->intent->get() ) {
            return [] === $this->intent->rewards() ? null : $this->rewards_error( $cart, $user_id );
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
            } elseif ( null === $row['reward'] || ! $quote->is_allowed() || $quote->get_value() <= 0 ) {
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

            case self::NOTICE_EXCLUSIVE_SLIDER:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( 'Remove your rewards to use a %s discount.', 'yayloyalty' ), $label );

            case self::NOTICE_EXCLUSIVE_REWARDS:
                /* translators: %s: the store's points label, as "Points". */
                return sprintf( __( 'Remove your %s discount to use rewards.', 'yayloyalty' ), $label );

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
     * commits through CommitWriter in one transaction: the slider's line
     * through commit(), reward lines through commit_rewards(). An order
     * carrying both kinds is skipped with REASON_REWARD_UNAVAILABLE.
     *
     * Postconditions, exactly one of:
     * - The order has no coupon line of ours: Outcome::skipped( REASON_NO_INTENT ), nothing else happens.
     * - Outcome::redeemed: the order carries `_yayloyalty_points_redeemed` /
     *   `_yayloyalty_redeem_value`, and `yayloyalty_points_redeemed`
     *   ( Outcome, \WC_Order ) fires after commit.
     * - Outcome::duplicate: this order was already committed.
     * - Outcome::skipped( reason ): nothing was debited, so every loyalty
     *   coupon line is taken off the order — it must not stay payable with
     *   a discount and no debit — and the adapter fails the checkout.
     * The session intent is cleared in the last three.
     *
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function commit( \WC_Order $order ): Outcome {
        $codes        = array_map( 'strval', $order->get_coupon_codes() );
        $has_slider   = in_array( CouponProvider::CODE, $codes, true );
        $reward_codes = array_values(
            array_filter(
                $codes,
                static function ( string $code ): bool {
                    return null !== CouponProvider::reward_id_of( $code );
                }
            )
        );

        if ( ! $has_slider && [] === $reward_codes ) {
            return Outcome::skipped( self::REASON_NO_INTENT );
        }

        if ( $has_slider && [] !== $reward_codes ) {
            $outcome = Outcome::skipped( self::REASON_REWARD_UNAVAILABLE );
        } elseif ( $has_slider ) {
            $reward  = $this->flexible_reward();
            $outcome = null === $reward ? Outcome::skipped( self::REASON_NO_REWARD ) : $this->writer->commit( $order, $reward );
        } else {
            $outcome = $this->writer->commit_rewards( $order, $this->offered_rewards_of( $reward_codes ) );
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
            foreach ( $codes as $code ) {
                if ( EarnContext::is_loyalty_code( $code ) ) {
                    $order->remove_coupon( $code );
                }
            }
        }

        return $outcome;
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

    /** The reward by id, read once per request. */
    private function find_reward( int $reward_id ): ?Reward {
        if ( ! array_key_exists( $reward_id, $this->reward_cache ) ) {
            $this->reward_cache[ $reward_id ] = $this->rewards->find_reward( $reward_id );
        }

        return $this->reward_cache[ $reward_id ];
    }

    /** The coupon the reward's handler describes; null for the slider's type, or a type with no handler (Lite). */
    private function coupon_props_of( Reward $reward ): ?array {
        return $this->registry->has( $reward->get_type() ) ? $this->registry->get( $reward->get_type() )->coupon_props( $reward ) : null;
    }
}
