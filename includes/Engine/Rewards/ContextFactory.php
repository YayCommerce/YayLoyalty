<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerReader;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the claim Context from WooCommerce, the customer row and the tiers
 * table — the one class on the quote path allowed to touch them, and it can
 * only read: it depends on CustomerReader, which has no write method, and
 * calls only TierRepository's find methods. The
 * subtotal it reports is net of every other coupon, so the clamp's cart
 * ceiling is what the customer would actually pay.
 */
final class ContextFactory {

    private CustomerReader $customers;

    private TierRepository $tiers;

    public function __construct( CustomerReader $customers ) {
        $this->customers = $customers;
        $this->tiers     = new TierRepository();
    }

    /**
     * The conditions gate's facts for $user_id. Postconditions: null for a guest; {0, null, 0}
     * for a user without a customer row; otherwise the row's lifetime_earned and tier_id, and
     * the rank of that tier while it is live, else 0 (a deleted tier ranks nowhere).
     */
    public function facts_for( ?int $user_id ): ?RewardFacts {
        if ( null === $user_id ) {
            return null;
        }

        return $this->facts_of( $this->customers->find_by_user_id( $user_id ) );
    }

    /** facts_for() for a signed-in user's already-loaded row, so from_cart() reads it once. */
    private function facts_of( ?Customer $customer ): RewardFacts {
        if ( null === $customer ) {
            return new RewardFacts( 0, null, 0 );
        }

        $tier_id = $customer->get_tier_id();
        $tier    = null === $tier_id ? null : $this->tiers->find_live( $tier_id );

        return new RewardFacts( $customer->get_lifetime_earned(), $tier_id, null === $tier ? 0 : $tier->get_tier_rank() );
    }

    /**
     * The cart-time Context. Postconditions: balance is the customer row's
     * `balance` (0 for a guest or a missing row); discountable_amount = the items'
     * total minus what every other applied coupon takes, computed here and
     * never read from the cart's stored totals, which are stale while
     * WooCommerce is loading coupons; currency = the one the cart is charged
     * in, with its rate; at = Dates::now(); the cart itself, for a handler
     * that dry-runs a coupon on it.
     */
    public function from_cart( \WC_Cart $cart, ?int $user_id, ?int $points_requested ): Context {
        $customer = null === $user_id ? null : $this->customers->find_by_user_id( $user_id );
        $currency = CurrencyRate::current();

        return new Context(
            $user_id,
            null === $customer ? 0 : $customer->get_balance(),
            $points_requested,
            $this->discountable_amount( $cart ),
            $currency,
            Dates::now(),
            null === $user_id ? null : $this->facts_of( $customer ),
            $cart,
            CurrencyRate::rate( $currency )
        );
    }

    /**
     * The commit-time Context, built from the order the cart became.
     * Preconditions: $order was just created from the cart; $balance is the
     * customer row's balance read under the lock (never re-read here).
     * Postconditions: points_requested = our coupon line's amount turned back
     * into points by Clamp::points_for(), the inverse of the value the cart
     * quoted; discountable_amount = the line items' subtotal minus every other
     * coupon line, on the basis prices were entered (tax included or not);
     * at = Dates::now(); the rate saved on the order, else the live one.
     */
    public function from_order( \WC_Order $order, Reward $reward, int $balance ): Context {
        $user_id = (int) $order->get_customer_id();
        $ours    = $this->points_discount_of( $order, $reward );
        $rate    = CurrencyRate::rate( $order->get_currency(), $order );

        return new Context(
            $user_id > 0 ? $user_id : null,
            $balance,
            Clamp::points_for( $ours, $reward, $rate ),
            max( 0.0, $this->items_subtotal( $order ) - ( $this->coupon_discounts_of( $order ) - $ours ) ),
            $order->get_currency(),
            Dates::now(),
            $this->facts_for( $user_id > 0 ? $user_id : null ),
            null,
            $rate
        );
    }

    /**
     * The commit-time Context for one reward coupon line of the order.
     * Preconditions as for from_order(). Postconditions: points_requested
     * null (the cost is fixed); discountable_amount = the line items' subtotal minus
     * every coupon line that is not a loyalty one; no cart, so the handler
     * answers the cost and the commit checks the line's own discount.
     */
    public function from_order_line( \WC_Order $order, Reward $reward, int $balance ): Context {
        $user_id = (int) $order->get_customer_id();
        $loyalty = 0.0;

        foreach ( $order->get_coupon_codes() as $code ) {
            if ( EarnContext::is_loyalty_code( (string) $code ) ) {
                $loyalty += $this->line_discount_of( $order, (string) $code );
            }
        }

        return new Context(
            $user_id > 0 ? $user_id : null,
            $balance,
            null,
            max( 0.0, $this->items_subtotal( $order ) - ( $this->coupon_discounts_of( $order ) - $loyalty ) ),
            $order->get_currency(),
            Dates::now(),
            $this->facts_for( $user_id > 0 ? $user_id : null ),
            null,
            CurrencyRate::rate( $order->get_currency(), $order )
        );
    }

    /** What the flexible discount's coupon line takes off the order: line_discount_of() the flexible reward's code. 0 without the line. */
    public function points_discount_of( \WC_Order $order, Reward $reward ): float {
        return $this->line_discount_of( $order, $reward->get_coupon_code() );
    }

    /** What one coupon code's lines take off the order, on the basis prices were entered: discount tax included when they include tax. 0 without the line. */
    public function line_discount_of( \WC_Order $order, string $code ): float {
        return $this->coupon_discounts_of( $order, $code );
    }

    /** The line items' subtotal before any coupon, on the basis prices were entered. */
    private function items_subtotal( \WC_Order $order ): float {
        $includes_tax = (bool) $order->get_prices_include_tax();
        $subtotal     = 0.0;

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item instanceof \WC_Order_Item_Product ) {
                $subtotal += (float) $item->get_subtotal() + ( $includes_tax ? (float) $item->get_subtotal_tax() : 0.0 );
            }
        }

        return $subtotal;
    }

    /** The order's coupon lines summed on the basis prices were entered; one code's lines when $code is given. */
    private function coupon_discounts_of( \WC_Order $order, ?string $code = null ): float {
        $includes_tax = (bool) $order->get_prices_include_tax();
        $total        = 0.0;

        foreach ( $order->get_items( 'coupon' ) as $item ) {
            if ( $item instanceof \WC_Order_Item_Coupon && ( null === $code || $code === $item->get_code() ) ) {
                $total += (float) $item->get_discount() + ( $includes_tax ? (float) $item->get_discount_tax() : 0.0 );
            }
        }

        return $total;
    }

    /**
     * What is left of the cart's items after every coupon but the loyalty
     * ones. Never calls $cart->get_coupons(): that loads our own coupons,
     * whose amounts are quoted from this method.
     */
    private function discountable_amount( \WC_Cart $cart ): float {
        $items_subtotal = 0.0;

        // The same basis WC_Discounts::set_items_from_cart() discounts from.
        foreach ( $cart->get_cart() as $item ) {
            if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
                $items_subtotal += (float) $item['data']->get_price() * (float) $item['quantity'];
            }
        }

        $coupons = [];

        foreach ( $cart->get_applied_coupons() as $code ) {
            if ( ! EarnContext::is_loyalty_code( (string) $code ) ) {
                $coupons[] = new \WC_Coupon( $code );
            }
        }

        if ( empty( $coupons ) ) {
            return max( 0.0, $items_subtotal );
        }

        $discounts = new \WC_Discounts( $cart );

        foreach ( self::in_cart_order( $coupons ) as $coupon ) {
            $discounts->apply_coupon( $coupon );
        }

        return max( 0.0, $items_subtotal - (float) array_sum( $discounts->get_discounts_by_coupon() ) );
    }

    /**
     * The order WC_Cart_Totals::get_coupons_from_cart() applies coupons in,
     * which decides what a percentage coupon is a percentage of. Also
     * RewardCoupons::probe()'s order for the merchant coupons.
     *
     * @param \WC_Coupon[] $coupons
     * @return \WC_Coupon[]
     */
    public static function in_cart_order( array $coupons ): array {
        $weights = [
            'fixed_product' => 1,
            'percent'       => 2,
            'fixed_cart'    => 3,
        ];
        $sorted  = [];

        foreach ( $coupons as $coupon ) {
            $sorted[] = [
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as its cart totals apply it.
                'sort'   => apply_filters( 'woocommerce_coupon_sort', $weights[ $coupon->get_discount_type() ] ?? 0, $coupon ),
                'coupon' => $coupon,
            ];
        }

        usort(
            $sorted,
            static function ( array $a, array $b ): int {
                if ( $a['sort'] !== $b['sort'] ) {
                    return $a['sort'] < $b['sort'] ? -1 : 1;
                }

                $limit_a = $a['coupon']->get_limit_usage_to_x_items();
                $limit_b = $b['coupon']->get_limit_usage_to_x_items();

                if ( $limit_a !== $limit_b ) {
                    return $limit_a < $limit_b ? -1 : 1;
                }

                if ( $a['coupon']->get_amount() === $b['coupon']->get_amount() ) {
                    return $b['coupon']->get_id() - $a['coupon']->get_id();
                }

                return $a['coupon']->get_amount() < $b['coupon']->get_amount() ? -1 : 1;
            }
        );

        return array_column( $sorted, 'coupon' );
    }
}
