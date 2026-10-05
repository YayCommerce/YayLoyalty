<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Engine\Points\ContextFactory as PointsContextFactory;
use YayLoyalty\Repositories\CustomerReader;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the claim Context from WooCommerce and the customer row — the one
 * class on the quote path allowed to touch either, and it can only read:
 * it depends on CustomerReader, which has no write method. The
 * subtotal it reports is net of every other coupon, so the clamp's cart
 * ceiling is what the customer would actually pay. Scaffold: stubs.
 */
final class ContextFactory {

    private CustomerReader $customers;

    public function __construct( CustomerReader $customers ) {
        $this->customers = $customers;
    }

    /**
     * The cart-time Context. Precondition: totals have been calculated for
     * every coupon except ours. Postconditions: balance is the customer row's
     * `balance` (0 for a guest or a missing row); cart_subtotal =
     * cart contents total after other coupons, excluding our own coupon's
     * discount; currency = the cart's; at = Dates::now(); facts from
     * Points\ContextFactory::customer_facts( user_id, at ).
     */
    public function from_cart( \WC_Cart $cart, ?int $user_id, ?int $points_requested ): Context { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): balance = the balance of customers->find_by_user_id( user_id ), 0 when there is no row; subtotal = cart->get_subtotal() + tax if prices incl. tax − (cart->get_discount_total() − our coupon's amount); currency = get_woocommerce_currency(); return new Context( …, Dates::now(), PointsContextFactory::customer_facts( user_id, at ) ).
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The commit-time Context, built from the order the cart became.
     * Preconditions: $order was just created from the cart; $balance is the
     * customer row's balance read under the lock (never re-read here).
     * Postconditions: cart_subtotal = order subtotal after every coupon but
     * ours; points_requested = our coupon line's discount converted at the
     * reward's rate (the provider quoted it at that rate in this same
     * request, so the conversion is exact); at = the order's creation date.
     */
    public function from_order( \WC_Order $order, int $balance ): Context { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): user_id from the order; subtotal from get_subtotal() minus every coupon item's discount except Points\Context::REDEEM_COUPON_CODE; points_requested = that coupon item's discount times the reward's points_per_currency_unit, rounded; currency get_currency(); at = get_date_created() UTC; facts as from_cart().
        throw new \RuntimeException( 'Not implemented' );
    }
}
