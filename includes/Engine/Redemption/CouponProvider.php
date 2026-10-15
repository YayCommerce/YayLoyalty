<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Materialises the discount as virtual coupons from the current quotes —
 * never a `shop_coupon` post on the 1.0 path: `yayloyalty-redeem` for the
 * slider, or one `yayloyalty-reward-{id}` per picked reward, never both.
 * Applied after every real coupon, so each loyalty amount is quoted against
 * what is left; stacks with them (`individual_use` false); no free shipping.
 * The amounts are re-derived on every totals run, so a cart change is
 * reflected without anything stored. The shopper removing a loyalty coupon
 * takes it out of the intent, so it is not applied again.
 */
final class CouponProvider {

    /** The earning engine already knows this code, so our own discount never counts as "used a coupon". */
    public const CODE = EarnContext::REDEEM_COUPON_CODE;

    /** A reward's virtual coupon is this prefix plus its id. */
    public const REWARD_PREFIX = EarnContext::REWARD_COUPON_PREFIX;

    /** Denial reasons that take a picked reward out of the session; any other leaves it there, unapplied. */
    private const DROPPING_REASONS = [
        RewardCoupons::REASON_MIN_ORDER,
        RewardCoupons::REASON_SALE_ITEMS,
        RewardCoupons::REASON_NO_VALUE,
        Result::REASON_NOT_LIVE,
    ];

    private RedemptionService $service;

    private SessionIntent $intent;

    /** @var array<int, true> Rewards already given the "was removed" notice this request. */
    private array $notified = [];

    public function __construct( RedemptionService $service, SessionIntent $intent ) {
        $this->service = $service;
        $this->intent  = $intent;
    }

    /**
     * Attaches the six hooks. sync_cart() runs last on
     * `woocommerce_before_calculate_totals`, after every other plugin's
     * coupon logic, and sync_before_check() runs before WooCommerce's own
     * coupon check. Called once at boot by the plugin.
     */
    public function register(): void {
        add_filter( 'woocommerce_get_shop_coupon_data', [ $this, 'virtual_coupon_data' ], 10, 2 );
        add_filter( 'woocommerce_coupon_sort', [ $this, 'sort_weight' ], 10, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'sync_cart' ], PHP_INT_MAX );
        add_action( 'woocommerce_removed_coupon', [ $this, 'on_removed' ] );
        // Before WC_Cart::check_cart_coupons() at priority 1.
        add_action( 'woocommerce_check_cart_items', [ $this, 'sync_before_check' ], 0 );
        add_filter( 'woocommerce_cart_totals_coupon_label', [ $this, 'label' ], 10, 2 );
    }

    /** `yayloyalty-reward-{id}`: the code of a reward's virtual coupon. */
    public static function reward_code( int $reward_id ): string {
        return self::REWARD_PREFIX . $reward_id;
    }

    /** The reward id a reward code carries; null for any other code, or an id below 1. */
    public static function reward_id_of( string $code ): ?int {
        if ( ! preg_match( '/^' . preg_quote( self::REWARD_PREFIX, '/' ) . '(\d+)$/D', $code, $matches ) ) {
            return null;
        }

        $reward_id = (int) $matches[1];

        return $reward_id > 0 ? $reward_id : null;
    }

    /** "Points discount", with the merchant's label: what the totals row reads instead of the code. */
    public static function display_label(): string {
        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( '%s discount', 'yayloyalty' ), SettingsHelper::points_label_plural() );
    }

    /** The classic totals row's label: "Points discount", or the reward's name. Postcondition: $label unchanged for every other coupon. */
    public function label( string $label, \WC_Coupon $coupon ): string {
        $code = (string) $coupon->get_code();

        if ( self::CODE === $code ) {
            return self::display_label();
        }

        $reward = $this->service->reward_for_code( $code );

        return null === $reward ? $label : $reward->get_name();
    }

    /** Tells the shopper a picked reward was taken off, by name, never by its code. Once per reward and request. */
    public function notice_removed( Reward $reward ): void {
        $reward_id = (int) $reward->get_id();

        if ( isset( $this->notified[ $reward_id ] ) || ! function_exists( 'wc_add_notice' ) ) {
            return;
        }

        $this->notified[ $reward_id ] = true;

        /* translators: %s: the reward's name, as "$5 off". */
        wc_add_notice( sprintf( __( '%s was removed: this order no longer qualifies.', 'yayloyalty' ), $reward->get_name() ), 'notice' );
    }

    /**
     * Precondition: $code is any coupon WooCommerce is loading, a code or
     * an id. Postcondition: $data untouched for every other code and when
     * there is no cart; for self::CODE an array with discount_type
     * `fixed_cart`, amount = quote()->get_value() (0 when the quote is
     * denied), individual_use false, free_shipping false, usage_limit 0,
     * no expiry, no restrictions. For a reward code,
     * RedemptionService::coupon_data_for(); when the reward is missing or no
     * handler describes it (deleted, or a downgrade to Lite), a `fixed_cart`
     * coupon at 0. WooCommerce checks cart coupons before totals run, and a
     * code with no data would be removed with a notice naming the code;
     * worth 0 it stays valid until sync_cart() drops it.
     *
     * @param mixed $data False, or another provider's array.
     * @param mixed $code The code or id WooCommerce is loading.
     * @return mixed
     */
    public function virtual_coupon_data( $data, $code ) {
        if ( ! is_string( $code ) ) {
            return $data;
        }

        if ( null !== self::reward_id_of( $code ) ) {
            $reward = $this->service->reward_for_code( $code );
            $reward = null === $reward ? null : $this->service->coupon_data_for( $reward, RedemptionService::cart() );

            return null !== $reward ? $reward : RewardCoupons::data(
                [
                    'discount_type'      => 'fixed_cart',
                    'amount'             => 0.0,
                    'minimum_amount'     => null,
                    'exclude_sale_items' => false,
                ]
            );
        }

        if ( self::CODE !== $code ) {
            return $data;
        }

        $cart = RedemptionService::cart();

        if ( null === $cart ) {
            return $data;
        }

        $quote = $this->service->quote( $cart, RedemptionService::shopper_id() );

        return [
            'discount_type'  => 'fixed_cart',
            'amount'         => $quote->is_allowed() ? $quote->get_value() : 0,
            'individual_use' => false,
            'free_shipping'  => false,
            'usage_limit'    => 0,
        ];
    }

    /**
     * WooCommerce sorts cart coupons by a per-coupon weight before applying
     * them (WC_Cart_Totals::get_coupons_from_cart, filter
     * `woocommerce_coupon_sort`). Postcondition: $sort unchanged for every
     * other coupon; PHP_INT_MAX for self::CODE, so every real coupon
     * discounts first and our amount is quoted against what is left. A
     * reward's weight follows its type, not the discount type it emits, so a
     * capped percent reward keeps its place: percent rewards, then fixed.
     */
    public function sort_weight( int $sort, \WC_Coupon $coupon ): int {
        $code = (string) $coupon->get_code();

        if ( self::CODE === $code ) {
            return PHP_INT_MAX;
        }

        $reward = null === self::reward_id_of( $code ) ? null : $this->service->reward_for_code( $code );

        return null === $reward ? $sort : self::weight_of( $reward );
    }

    /** A reward coupon's sort weight: percent rewards before fixed ones, both after every real coupon and before the slider's. */
    private static function weight_of( Reward $reward ): int {
        return RewardType::PERCENT_COUPON === $reward->get_type() ? PHP_INT_MAX - 2 : PHP_INT_MAX - 1;
    }

    /**
     * Keeps the loyalty coupon lines in step with the intent: the slider's
     * code while its quote applies points, or one code per picked reward
     * whose quote is allowed with a value, never both. Every other loyalty
     * code is taken off, so a guest never sees a zero-value line and the
     * slider and rewards never meet. The loyalty codes go after the merchant
     * codes in the order the totals list them: percent rewards, then fixed,
     * each in the order picked. A picked reward that stopped qualifying
     * (REASONS that drop) leaves the session with notice_removed(); one the
     * balance cannot cover stays picked, unapplied. Edits the applied-coupon
     * list directly: apply_coupon() and remove_coupon() add notices, and the
     * latter fires `woocommerce_removed_coupon`, which must mean the shopper
     * removed it. Never touches a merchant coupon and never triggers a
     * recalculation.
     */
    public function sync_cart( \WC_Cart $cart ): void {
        $applied    = $cart->get_applied_coupons();
        $points     = $this->intent->get();
        $reward_ids = $this->intent->rewards();
        $has_ours   = [] !== array_filter( $applied, [ EarnContext::class, 'is_loyalty_code' ] );

        // Nothing asked and nothing to take off: the common case, answered without a quote.
        if ( ! $has_ours && 0 === $points && [] === $reward_ids ) {
            return;
        }

        $wanted   = $points > 0 ? $this->wanted_slider( $cart ) : $this->wanted_rewards( $cart );
        $merchant = array_filter(
            $applied,
            static function ( $code ): bool {
                return ! EarnContext::is_loyalty_code( (string) $code );
            }
        );
        $next     = array_values( array_merge( $merchant, $wanted ) );

        if ( $next !== $applied ) {
            $cart->set_applied_coupons( $next );
        }
    }

    /**
     * Classic cart and checkout pages check cart coupons before their totals
     * run, so a reward that stopped qualifying since the last totals run (a
     * raised minimum, an item now on sale) would be removed by WooCommerce
     * with a notice naming its code. One totals run first lets sync_cart()
     * drop it with notice_removed(). Skipped when no loyalty code is applied.
     */
    public function sync_before_check(): void {
        $cart = RedemptionService::cart();

        if ( null !== $cart && [] !== array_filter( $cart->get_applied_coupons(), [ EarnContext::class, 'is_loyalty_code' ] ) ) {
            $cart->calculate_totals();
        }
    }

    /**
     * The shopper removed a coupon, or WooCommerce removed it as invalid. The slider's clears the intent and a
     * reward's leaves the picked rewards, or the next totals run would apply it again.
     *
     * @param mixed $code
     */
    public function on_removed( $code ): void {
        if ( self::CODE === $code ) {
            $this->intent->clear();

            return;
        }

        $reward_id = is_string( $code ) ? self::reward_id_of( $code ) : null;

        if ( null !== $reward_id ) {
            $this->intent->remove_reward( $reward_id );
        }
    }

    /**
     * The slider's code while its quote applies points, else nothing.
     *
     * @return string[]
     */
    private function wanted_slider( \WC_Cart $cart ): array {
        $quote = $this->service->quote( $cart, RedemptionService::shopper_id() );

        return $quote->is_allowed() && $quote->get_points() > 0 ? [ self::CODE ] : [];
    }

    /**
     * One code per picked reward whose quote is allowed with a value: percent rewards, then fixed, each in the
     * order picked. Drops from the session, with notice_removed(), each one denied for a reason in DROPPING_REASONS.
     *
     * @return string[]
     */
    private function wanted_rewards( \WC_Cart $cart ): array {
        $percent = [];
        $fixed   = [];

        foreach ( $this->service->quote_rewards( $cart, RedemptionService::shopper_id() ) as $reward_id => $row ) {
            $quote = $row['quote'];

            if ( $quote->is_allowed() && $quote->get_value() > 0 ) {
                if ( RewardType::PERCENT_COUPON === $row['reward']->get_type() ) {
                    $percent[] = self::reward_code( $reward_id );
                } else {
                    $fixed[] = self::reward_code( $reward_id );
                }
            } elseif ( ! $quote->is_allowed() && in_array( $quote->get_reason(), self::DROPPING_REASONS, true ) ) {
                $this->intent->remove_reward( $reward_id );

                if ( null !== $row['reward'] ) {
                    $this->notice_removed( $row['reward'] );
                }
            }
        }

        return array_merge( $percent, $fixed );
    }
}
