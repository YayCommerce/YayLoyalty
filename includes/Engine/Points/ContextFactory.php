<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a Context from WooCommerce objects.
 *
 * THE ONLY CLASS IN THE ENGINE THAT TOUCHES WOOCOMMERCE. Everything after
 * this point works on plain values, which is what keeps the calculation
 * testable without a bootstrap.
 *
 * Amounts are left in the order's currency; `base_rate` carries the
 * conversion to the store's base currency and is 1.0 unless a multi-currency
 * integration says otherwise through the `yayloyalty_context_base_rate`
 * filter. That filter is the whole YayCurrency seam: nothing here changes
 * when it lands.
 */
final class ContextFactory {

    /**
     * @param int $points_earned_today From the ledger, by the caller; 0 in a preview.
     */
    public static function from_order( \WC_Order $order, int $points_earned_today = 0 ): Context {
        $user_id    = (int) $order->get_customer_id();
        $line_items = [];

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) {
                continue;
            }

            $line_items[] = new ContextLineItem(
                (int) $item->get_product_id(),
                $item->get_variation_id() ? (int) $item->get_variation_id() : null,
                self::category_ids( (int) $item->get_product_id() ),
                (int) $item->get_quantity(),
                (float) $item->get_subtotal(),
                (float) $item->get_total(),
                (float) $item->get_total_tax()
            );
        }

        $loyalty_discount = 0.0;

        foreach ( $order->get_items( 'coupon' ) as $coupon ) {
            if ( $coupon instanceof \WC_Order_Item_Coupon && Context::REDEEM_COUPON_CODE === $coupon->get_code() ) {
                $loyalty_discount += (float) $coupon->get_discount();
            }
        }

        $created = $order->get_date_created();

        return new Context(
            $user_id > 0 ? $user_id : null,
            $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : Dates::now(),
            (string) $order->get_currency(),
            self::base_rate( (string) $order->get_currency(), $order ),
            $line_items,
            (float) $order->get_subtotal(),
            (float) $order->get_total_discount(),
            $loyalty_discount,
            (array) $order->get_coupon_codes(),
            self::user_roles( $user_id ),
            $order->get_id() > 0 ? (int) $order->get_id() : null,
            null,
            $points_earned_today
        );
    }

    /**
     * A single-line context at the product's current price, for the
     * product-page "you'll earn N points" preview.
     */
    public static function from_product( \WC_Product $product, int $qty = 1, ?int $user_id = null ): Context {
        $qty      = max( 1, $qty );
        $subtotal = (float) wc_get_price_excluding_tax( $product, [ 'qty' => $qty ] );
        $tax      = (float) wc_get_price_including_tax( $product, [ 'qty' => $qty ] ) - $subtotal;
        $parent   = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();

        $line = new ContextLineItem(
            $parent,
            $product->get_parent_id() ? (int) $product->get_id() : null,
            self::category_ids( $parent ),
            $qty,
            $subtotal,
            $subtotal,
            max( 0.0, $tax )
        );

        $currency = get_woocommerce_currency();

        return new Context(
            $user_id,
            Dates::now(),
            $currency,
            self::base_rate( $currency, null ),
            [ $line ],
            $subtotal,
            0.0,
            0.0,
            [],
            self::user_roles( (int) $user_id )
        );
    }

    /**
     * The cart as it stands, for the cart/checkout earn preview.
     */
    public static function from_cart( \WC_Cart $cart, ?int $user_id = null ): Context {
        $line_items = [];

        foreach ( $cart->get_cart() as $cart_item ) {
            $product_id = (int) ( $cart_item['product_id'] ?? 0 );

            if ( $product_id < 1 ) {
                continue;
            }

            $line_items[] = new ContextLineItem(
                $product_id,
                ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : null,
                self::category_ids( $product_id ),
                (int) ( $cart_item['quantity'] ?? 1 ),
                (float) ( $cart_item['line_subtotal'] ?? 0 ),
                (float) ( $cart_item['line_total'] ?? 0 ),
                (float) ( $cart_item['line_tax'] ?? 0 )
            );
        }

        $coupon_codes = (array) $cart->get_applied_coupons();
        $currency     = get_woocommerce_currency();

        return new Context(
            $user_id,
            Dates::now(),
            $currency,
            self::base_rate( $currency, null ),
            $line_items,
            (float) $cart->get_subtotal(),
            (float) $cart->get_discount_total(),
            in_array( Context::REDEEM_COUPON_CODE, $coupon_codes, true )
                ? (float) $cart->get_coupon_discount_amount( Context::REDEEM_COUPON_CODE )
                : 0.0,
            $coupon_codes,
            self::user_roles( (int) $user_id )
        );
    }

    /**
     * Categories of the PARENT product: variations carry none of their own.
     *
     * @return int[]
     */
    private static function category_ids( int $product_id ): array {
        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );

        return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
    }

    /**
     * @return string[]
     */
    private static function user_roles( int $user_id ): array {
        if ( $user_id < 1 ) {
            return [];
        }

        $user = get_userdata( $user_id );

        return $user ? array_values( (array) $user->roles ) : [];
    }

    /**
     * Multiply order-currency amounts by this to reach the store's base currency.
     *
     * @param \WC_Order|null $order Present for orders, so an integration can read its own meta.
     */
    private static function base_rate( string $currency, ?\WC_Order $order ): float {
        /**
         * Conversion rate from the event's currency to the store base currency.
         *
         * 1.0 unless a multi-currency integration (YayCurrency) supplies one.
         *
         * @param float          $rate
         * @param string         $currency
         * @param \WC_Order|null $order
         */
        $rate = (float) apply_filters( 'yayloyalty_context_base_rate', 1.0, $currency, $order );

        return $rate > 0 ? $rate : 1.0;
    }
}
