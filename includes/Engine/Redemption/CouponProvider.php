<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Materialises the discount as a virtual coupon from the current quote —
 * never a `shop_coupon` post on the 1.0 path. Applied last, so the clamp's
 * cart ceiling is the subtotal every real coupon already reduced; stacks
 * with them (`individual_use` false); no free shipping (that is a Pro
 * reward type, not a property of this discount). The amount is
 * re-derived from RedemptionService::quote() on every totals run, so a
 * cart change is reflected without anything stored. The shopper removing
 * the coupon clears the intent, so it is not applied again.
 */
final class CouponProvider {

    /** The earning engine already knows this code, so our own discount never counts as "used a coupon". */
    public const CODE = EarnContext::REDEEM_COUPON_CODE;

    private RedemptionService $service;

    private SessionIntent $intent;

    public function __construct( RedemptionService $service, SessionIntent $intent ) {
        $this->service = $service;
        $this->intent  = $intent;
    }

    /**
     * Attaches the five hooks. sync_cart() runs last on
     * `woocommerce_before_calculate_totals`, after every other plugin's
     * coupon logic. Called once at boot by the plugin.
     */
    public function register(): void {
        add_filter( 'woocommerce_get_shop_coupon_data', [ $this, 'virtual_coupon_data' ], 10, 2 );
        add_filter( 'woocommerce_coupon_sort', [ $this, 'sort_weight' ], 10, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'sync_cart' ], PHP_INT_MAX );
        add_action( 'woocommerce_removed_coupon', [ $this, 'on_removed' ] );
        add_filter( 'woocommerce_cart_totals_coupon_label', [ $this, 'label' ], 10, 2 );
    }

    /** "Points discount", with the merchant's label: what the totals row reads instead of the code. */
    public static function display_label(): string {
        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( '%s discount', 'yayloyalty' ), SettingsHelper::points_label_plural() );
    }

    /** The classic totals row's label. Postcondition: $label unchanged for every other coupon. */
    public function label( string $label, \WC_Coupon $coupon ): string {
        return self::CODE === $coupon->get_code() ? self::display_label() : $label;
    }

    /**
     * Precondition: $code is any coupon WooCommerce is loading, a code or
     * an id. Postcondition: $data untouched for every other code and when
     * there is no cart; for self::CODE an array with discount_type
     * `fixed_cart`, amount = quote()->get_value() (0 when the quote is
     * denied), individual_use false, free_shipping false, usage_limit 0,
     * no expiry, no restrictions.
     *
     * @param mixed $data False, or another provider's array.
     * @param mixed $code The code or id WooCommerce is loading.
     * @return mixed
     */
    public function virtual_coupon_data( $data, $code ) {
        if ( ! is_string( $code ) || self::CODE !== $code ) {
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
     * discounts first and our amount is quoted against what is left.
     */
    public function sort_weight( int $sort, \WC_Coupon $coupon ): int {
        return self::CODE === $coupon->get_code() ? PHP_INT_MAX : $sort;
    }

    /**
     * Keeps the coupon line in step with the quote: on the cart when the
     * quote is allowed with points above 0, off it otherwise, so a guest
     * never sees a zero-value line. Edits the applied-coupon list directly:
     * apply_coupon() and remove_coupon() add notices, and the latter fires
     * `woocommerce_removed_coupon`, which must mean the shopper removed it.
     * Never touches any other coupon and never triggers a recalculation.
     */
    public function sync_cart( \WC_Cart $cart ): void {
        $applied    = $cart->get_applied_coupons();
        $is_applied = in_array( self::CODE, $applied, true );

        // Nothing asked and nothing to take off: the common case, answered without a quote.
        if ( ! $is_applied && 0 === $this->intent->get() ) {
            return;
        }

        $quote     = $this->service->quote( $cart, RedemptionService::shopper_id() );
        $has_value = $quote->is_allowed() && $quote->get_points() > 0;

        if ( $has_value && ! $is_applied ) {
            $applied[] = self::CODE;
            $cart->set_applied_coupons( $applied );
        } elseif ( ! $has_value && $is_applied ) {
            $cart->set_applied_coupons( array_values( array_diff( $applied, [ self::CODE ] ) ) );
        }
    }

    /**
     * The shopper removed a coupon. Ours clears the intent, or the next totals run would apply it again.
     *
     * @param mixed $code
     */
    public function on_removed( $code ): void {
        if ( self::CODE === $code ) {
            $this->intent->clear();
        }
    }
}
