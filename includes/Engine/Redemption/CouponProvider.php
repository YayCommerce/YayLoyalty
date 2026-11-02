<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RedemptionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Materialises the discount as virtual coupons from the current quotes —
 * never a `shop_coupon` post on the 1.0 path: each under its reward's stored
 * code (`yayloyalty-` plus 8 characters), the flexible reward's for the
 * flexible discount and one per picked reward, together.
 * Applied after every real coupon, so each loyalty amount is quoted against
 * what is left; stacks with them (`individual_use` false); no free shipping.
 * The amounts are re-derived on every totals run, so a cart change is
 * reflected without anything stored. The shopper removing a loyalty coupon
 * takes it out of the intent, so it is not applied again.
 */
final class CouponProvider {

    /**
     * Denial reasons that take a picked reward out of the session; any other leaves it there, unapplied. A program
     * gate's reasons hide the Rewards panel, so nothing else could take the reward out before checkout refuses it.
     */
    private const DROPPING_REASONS = [
        RewardCoupons::REASON_MIN_ORDER,
        RewardCoupons::REASON_SALE_ITEMS,
        RewardCoupons::REASON_NO_VALUE,
        Result::REASON_NOT_LIVE,
        ...ProgramGate::REASONS,
    ];

    private RedemptionService $service;

    private SessionIntent $intent;

    /** Read-only: whether an order's redemption debited a loyalty code put on it. */
    private RedemptionRepository $redemptions;

    /** @var array<int, true> Rewards already given the "was removed" notice this request. */
    private array $notified = [];

    public function __construct( RedemptionService $service, SessionIntent $intent, RedemptionRepository $redemptions ) {
        $this->service     = $service;
        $this->intent      = $intent;
        $this->redemptions = $redemptions;
    }

    /**
     * Attaches the seven hooks. sync_cart() runs last on
     * `woocommerce_before_calculate_totals`, after every other plugin's
     * coupon logic, and sync_before_check() runs before WooCommerce's own
     * coupon check. only_redeemed_on_order() sits on `woocommerce_coupon_is_valid`:
     * the admin "Apply coupon" and REST v3 validate through WC_Discounts( $order ),
     * while checkout and recalculate_coupons() do not. Called once at boot by the plugin.
     */
    public function register(): void {
        add_filter( 'woocommerce_get_shop_coupon_data', [ $this, 'virtual_coupon_data' ], 10, 2 );
        add_filter( 'woocommerce_coupon_sort', [ $this, 'sort_weight' ], 10, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'sync_cart' ], PHP_INT_MAX );
        add_action( 'woocommerce_removed_coupon', [ $this, 'on_removed' ] );
        // Before WC_Cart::check_cart_coupons() at priority 1.
        add_action( 'woocommerce_check_cart_items', [ $this, 'sync_before_check' ], 0 );
        add_filter( 'woocommerce_cart_totals_coupon_label', [ $this, 'label' ], 10, 2 );
        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'only_redeemed_on_order' ], 10, 3 );
    }

    /**
     * Refuses a loyalty code on an order unless that order has an `applied` redemption row for it, so no order
     * carries a loyalty discount no ledger row debited. A REST v3 update that keeps an existing line still passes.
     * Postcondition: $valid unchanged for a cart, for any other coupon and for a debited code.
     *
     * @param mixed $valid
     * @return mixed
     * @throws \Exception With the refusal WooCommerce shows; WC_Discounts::is_coupon_valid() catches it.
     */
    public function only_redeemed_on_order( $valid, \WC_Coupon $coupon, \WC_Discounts $discounts ) {
        $order = $discounts->get_object();
        $code  = (string) $coupon->get_code();

        if ( ! $order instanceof \WC_Order || ! EarnContext::is_loyalty_code( $code ) ) {
            return $valid;
        }

        $row = $this->redemptions->find_for_order_code( (int) $order->get_id(), $code );

        if ( null === $row || Redemption::STATUS_APPLIED !== $row->get_status() ) {
            throw new \Exception( esc_html__( 'This loyalty discount can only be applied by the customer at checkout.', 'yayloyalty' ) );
        }

        return $valid;
    }

    /** The points discount's name, as the merchant set it; "Points discount", with the merchant's label, when there is no reward. */
    public static function display_label( ?Reward $reward = null ): string {
        if ( null !== $reward ) {
            return $reward->get_name();
        }

        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( '%s discount', 'yayloyalty' ), SettingsHelper::points_label_plural() );
    }

    /** The classic totals row's label: the points discount's or the reward's name. Postcondition: $label unchanged for every other coupon. */
    public function label( string $label, \WC_Coupon $coupon ): string {
        $reward = $this->service->reward_for_code( (string) $coupon->get_code() );

        if ( RedemptionService::is_flexible_discount( $reward ) ) {
            return self::display_label( $this->service->live_flexible_reward() );
        }

        return null === $reward ? $label : $reward->get_name();
    }

    /** Tells the shopper a picked reward was taken off, by name, never by its code. Once per reward and request. */
    public function notice_removed( Reward $reward ): void {
        $reward_id = (int) $reward->get_id();

        if ( isset( $this->notified[ $reward_id ] ) ) {
            return;
        }

        $this->notified[ $reward_id ] = true;

        self::notice_dropped( $reward->get_name() );
    }

    /** "{name} was removed: this order no longer qualifies.", for a picked reward or the points discount. */
    private static function notice_dropped( string $name ): void {
        /* translators: %s: the reward's name, as "$5 off". */
        self::add_notice( sprintf( __( '%s was removed: this order no longer qualifies.', 'yayloyalty' ), $name ) );
    }

    /** Shows $line in WooCommerce's notices, as a `notice`; quiet without WooCommerce. */
    public static function add_notice( string $line ): void {
        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( $line, 'notice' );
        }
    }

    /**
     * Precondition: $code is any coupon WooCommerce is loading, a code or
     * an id. Postcondition: $data untouched for every code that is not a
     * loyalty code, before any query, and for the flexible discount's when there is no
     * cart; for the flexible discount's code an array with discount_type
     * `fixed_cart`, amount = quote()->get_value() (0 when the quote is
     * denied), individual_use false, free_shipping false, usage_limit 0,
     * no expiry, no restrictions. For a catalog reward's code,
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
        if ( ! is_string( $code ) || ! EarnContext::is_loyalty_code( $code ) ) {
            return $data;
        }

        $reward = $this->service->reward_for_code( $code );

        if ( ! RedemptionService::is_flexible_discount( $reward ) ) {
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
     * other coupon; PHP_INT_MAX for the flexible discount's, so every real coupon
     * discounts first and our amount is quoted against what is left. A
     * reward's weight follows its type, not the discount type it emits, so a
     * capped percent reward keeps its place: percent rewards, then fixed.
     */
    public function sort_weight( int $sort, \WC_Coupon $coupon ): int {
        $reward = $this->service->reward_for_code( (string) $coupon->get_code() );

        if ( RedemptionService::is_flexible_discount( $reward ) ) {
            return PHP_INT_MAX;
        }

        return null === $reward ? $sort : self::weight_of( $reward );
    }

    /** A reward coupon's sort weight: percent rewards before fixed ones, both after every real coupon and before the flexible discount's. */
    private static function weight_of( Reward $reward ): int {
        return RewardType::PERCENT_COUPON === $reward->get_type() ? PHP_INT_MAX - 2 : PHP_INT_MAX - 1;
    }

    /**
     * Keeps the loyalty coupon lines in step with the intent: one code per
     * picked reward whose quote is allowed with a value, and the flexible discount's
     * code while its quote applies points. Every other loyalty code is taken
     * off, so a guest never sees a zero-value line. The loyalty codes go
     * after the merchant codes in the order the totals list them: percent
     * rewards, then fixed, each in the order picked, then the flexible discount, which
     * is quoted against what the rewards leave. A picked reward that stopped qualifying
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

        $wanted   = array_merge(
            [] === $reward_ids ? [] : $this->resolve_rewards( $cart ),
            $points > 0 ? $this->resolve_flexible_discount( $cart ) : []
        );
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
     * The shopper removed a coupon, or WooCommerce removed it as invalid. The flexible discount's code clears its points and a
     * reward's leaves the picked rewards, or the next totals run would apply it again; the other kind stays.
     *
     * @param mixed $code
     */
    public function on_removed( $code ): void {
        $reward = is_string( $code ) ? $this->service->reward_for_code( $code ) : null;

        if ( RedemptionService::is_flexible_discount( $reward ) ) {
            $this->intent->set( 0 );
        } elseif ( null !== $reward ) {
            $this->intent->remove_reward( (int) $reward->get_id() );
        }
    }

    /**
     * The flexible discount's code while its quote applies points, else nothing. When asking for everything is refused too,
     * the card shows no field to change the amount and checkout would refuse it on every try, so the amount
     * leaves the session with the "was removed" notice.
     *
     * @return string[]
     */
    private function resolve_flexible_discount( \WC_Cart $cart ): array {
        $user_id = RedemptionService::shopper_id();
        $quote   = $this->service->quote( $cart, $user_id );

        if ( $quote->is_allowed() && $quote->get_points() > 0 ) {
            return [ $this->service->flexible_discount_code() ];
        }

        if ( ! $this->service->ceiling( $cart, $user_id )->is_allowed() ) {
            $this->intent->set( 0 );
            self::notice_dropped( self::display_label( $this->service->live_flexible_reward() ) );
        }

        return [];
    }

    /**
     * One code per picked reward whose quote is allowed with a value: percent rewards, then fixed, each in the
     * order picked. Drops from the session each one denied for a reason in DROPPING_REASONS: one denied `not_live`
     * is recorded for the card's notice with SessionIntent::add_dropped(); any other reason gets notice_removed().
     *
     * @return string[]
     */
    private function resolve_rewards( \WC_Cart $cart ): array {
        $percent_codes = [];
        $fixed_codes   = [];

        foreach ( $this->service->quote_rewards( $cart, RedemptionService::shopper_id() ) as $reward_id => $row ) {
            $quote = $row['quote'];

            if ( RedemptionService::applies( $quote ) ) {
                if ( RewardType::PERCENT_COUPON === $row['reward']->get_type() ) {
                    $percent_codes[] = $row['reward']->get_coupon_code();
                } else {
                    $fixed_codes[] = $row['reward']->get_coupon_code();
                }
            } elseif ( ! $quote->is_allowed() && in_array( $quote->get_reason(), self::DROPPING_REASONS, true ) ) {
                $this->intent->remove_reward( $reward_id );

                if ( Result::REASON_NOT_LIVE === $quote->get_reason() ) {
                    // A deleted reward has no row, so no name: the card words it generically.
                    $this->intent->add_dropped( null === $row['reward'] ? '' : $row['reward']->get_name() );
                } elseif ( null !== $row['reward'] ) {
                    $this->notice_removed( $row['reward'] );
                }
            }
        }

        return array_merge( $percent_codes, $fixed_codes );
    }
}
