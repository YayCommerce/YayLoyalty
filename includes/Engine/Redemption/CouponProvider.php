<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Points\Context as EarnContext;

defined( 'ABSPATH' ) || exit;

/**
 * Materialises the discount as a virtual coupon from the current quote —
 * never a `shop_coupon` post on the 1.0 path. Applied last, so the clamp's
 * cart ceiling is the subtotal every real coupon already reduced; stacks
 * with them (`individual_use` false); no free shipping (that is a Pro
 * reward type, not a property of this discount). The amount is
 * re-derived from RedemptionService::quote() on every totals run, so a
 * cart change is reflected without anything stored. Scaffold: stubs.
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
     * Attaches the three hooks: `woocommerce_get_shop_coupon_data` →
     * virtual_coupon_data(), `woocommerce_coupon_sort` → sort_weight(),
     * `woocommerce_before_calculate_totals` → sync_cart(). Called once at
     * boot by the plugin, storefront requests only.
     */
    public function register(): void {
        // TODO(F08): add_filter for the three hooks named in the docblock, sync_cart at a priority after every other plugin's coupon logic.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Precondition: $code is any coupon WooCommerce is loading. Postcondition:
     * $data untouched for every other code; for self::CODE an array with
     * discount_type `fixed_cart`, amount = quote()->get_value() (0 when the
     * quote is denied or applies 0 points), individual_use false,
     * free_shipping false, usage_limit 0, no expiry, no restrictions.
     *
     * @param mixed  $data False, or another provider's array.
     * @return mixed
     */
    public function virtual_coupon_data( $data, string $code ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): strict compare code to self::CODE; quote via service->quote( WC()->cart, get_current_user_id() ?: null ); return the fixed_cart array with individual_use false.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * WooCommerce sorts cart coupons by a per-coupon weight before applying
     * them (WC_Cart_Totals::get_coupons_from_cart, filter
     * `woocommerce_coupon_sort`). Postcondition: $sort unchanged for every
     * other coupon; PHP_INT_MAX for self::CODE, so every real coupon
     * discounts first and our amount is quoted against what is left.
     */
    public function sort_weight( int $sort, \WC_Coupon $coupon ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): return PHP_INT_MAX when the coupon's code is self::CODE, else the given sort.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * Keeps the coupon line in step with the intent: applied when
     * SessionIntent::get() > 0 and not yet applied, removed when the intent
     * is 0. Never touches any other coupon. Precondition: called before
     * WooCommerce calculates totals; must not trigger a recalculation itself.
     */
    public function sync_cart( \WC_Cart $cart ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): intent > 0 && ! cart->has_discount( self::CODE ) → cart->apply_coupon( self::CODE ) with WC's notice suppressed; intent 0 && has_discount → cart->remove_coupon( self::CODE ).
        throw new \RuntimeException( 'Not implemented' );
    }
}
