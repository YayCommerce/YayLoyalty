<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The only class in the engine that touches WooCommerce. Amounts stay in
 * the order's currency; base_rate carries the conversion and is the whole
 * multi-currency seam.
 */
final class ContextFactory {

    /**
     * The award-path Context: occurred_at is the order's creation date, so
     * the same order scores the same way at creation and at award, whatever
     * campaigns changed in between.
     *
     * @param int $points_earned_today From the ledger, by the caller; 0 in a preview.
     * @throws \InvalidArgumentException When the order carries no creation date; the caller decides, never now().
     */
    public static function from_order( \WC_Order $order, int $points_earned_today = 0 ): Context {
        $created = $order->get_date_created();

        if ( ! $created ) {
            throw new \InvalidArgumentException(
                sprintf( 'YayLoyalty: order %d has no creation date to evaluate at.', (int) $order->get_id() )
            );
        }

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

        return new Context(
            $user_id > 0 ? $user_id : null,
            gmdate( Dates::MYSQL_FORMAT, $created->getTimestamp() ),
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
     * The signup award Context: no lines, no amounts; occurred_at is the
     * registration time so a signup campaign that ended since still applies
     * to a late-processed registration.
     *
     * @param string $registered_at UTC MySQL datetime (wp_users.user_registered is already UTC).
     */
    public static function from_user( int $user_id, string $registered_at ): Context { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): user_roles( $user_id ); currency from get_woocommerce_currency(); empty lines, zero amounts, no coupons.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The review award Context: occurred_at is the comment's GMT date;
     * `extra[ ReviewHandler::EXTRA_VERIFIED_PURCHASE ]` is set from the
     * comment's `verified` meta so require_verified can be decided purely.
     */
    public static function from_comment( \WP_Comment $comment ): Context { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): comment_ID, user_id, comment_date_gmt, verified meta via get_comment_meta( 'verified' ); product from comment_post_ID for one_per_product.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The customer facts conditions compare against, read here — the one
     * impure class — so the evaluator behind `yayloyalty_campaign_conditions_evaluator`
     * can be pure. Replaces the reads Pro\Services\ConditionsEvaluator does
     * today (wc_get_orders, wc_get_customer_order_count, CustomerRepository).
     * Null for a guest. Callers pass the result as Context's last argument.
     */
    public static function customer_facts( ?int $user_id ): ?CustomerFacts { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F01-4): null when no user; CustomerRepository lifetime_earned; wc_get_customer_order_count; the 100 newest paid orders for spend_by_currency and purchased product/category ids; tier_id null until tiers exist.
        throw new \RuntimeException( 'Not implemented' );
    }

    /** For the product-page earn preview. */
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

    /** For the cart/checkout earn preview. */
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
     * Of the parent: variations carry no categories.
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

    private static function base_rate( string $currency, ?\WC_Order $order ): float {
        /**
         * Event currency → store base currency. 1.0 unless a multi-currency integration supplies one.
         *
         * @param float          $rate
         * @param string         $currency
         * @param \WC_Order|null $order
         */
        $rate = (float) apply_filters( 'yayloyalty_context_base_rate', 1.0, $currency, $order );

        return $rate > 0 ? $rate : 1.0;
    }
}
