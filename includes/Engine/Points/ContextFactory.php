<?php
namespace YayLoyalty\Engine\Points;

use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Contexts from WooCommerce objects. With CustomerFactsReader, the only engine classes that touch WooCommerce.
 * Amounts stay in the order's currency. to_base_factor carries the conversion and is the whole multi-currency seam.
 */
final class ContextFactory {

    /**
     * occurred_at is the order's creation date, so the order scores the same at creation and at award,
     * whatever campaigns changed in between. A caller scoring an old order against today's rules passes $occurred_at.
     *
     * @param string|null $occurred_at UTC MySQL datetime to score at; null for the order's creation date.
     * @throws \InvalidArgumentException When the order carries no creation date; the caller decides, never now().
     */
    public static function from_order( \WC_Order $order, ?string $occurred_at = null ): Context {
        $created = $order->get_date_created();

        if ( ! $created && null === $occurred_at ) {
            throw new \InvalidArgumentException(
                sprintf( 'YayLoyalty: order %d has no creation date to evaluate at.', (int) $order->get_id() )
            );
        }

        $user_id     = (int) $order->get_customer_id();
        $occurred_at = $occurred_at ?? gmdate( Dates::MYSQL_FORMAT, $created->getTimestamp() );
        $order_id    = $order->get_id() > 0 ? (int) $order->get_id() : null;
        $line_items  = [];

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item instanceof \WC_Order_Item_Product ) {
                $line_items[] = self::line_item( $item );
            }
        }

        return new Context(
            $user_id > 0 ? $user_id : null,
            $occurred_at,
            (string) $order->get_currency(),
            self::to_base_factor( (string) $order->get_currency(), $order ),
            $line_items,
            (float) $order->get_subtotal(),
            (float) $order->get_total_discount(),
            (array) $order->get_coupon_codes(),
            self::user_roles( $user_id ),
            $order_id,
            null,
            [
                Context::EXTRA_PAYMENT_METHOD   => (string) $order->get_payment_method(),
                Context::EXTRA_BILLING_COUNTRY  => (string) $order->get_billing_country(),
                Context::EXTRA_SHIPPING_COUNTRY => (string) $order->get_shipping_country(),
            ],
            static fn() => self::customer_facts( $user_id, $occurred_at, $order_id )
        );
    }

    /**
     * A user event with no order or comment: a signup passes the registration time, so a signup campaign that
     * has since ended still applies to a late-processed registration; the birthday run passes now.
     *
     * @param string               $occurred_at The event's UTC MySQL datetime (wp_users.user_registered is already UTC).
     * @param array<string, mixed> $extra       Facts the trigger's handler reads, such as the birthday's year.
     */
    public static function from_user( int $user_id, string $occurred_at, array $extra = [] ): Context {
        $currency = get_woocommerce_currency();

        return new Context(
            $user_id,
            $occurred_at,
            $currency,
            self::to_base_factor( $currency, null ),
            [],
            0.0,
            0.0,
            [],
            self::user_roles( $user_id ),
            null,
            null,
            $extra,
            static fn() => self::customer_facts( $user_id, $occurred_at )
        );
    }

    /**
     * occurred_at is the comment's GMT date. The verified-purchase and already-earned flags are read
     * into `extra` here, so ReviewHandler decides purely. No lines: the reviewed product is not an order line.
     *
     * @throws \InvalidArgumentException When the comment has no GMT date (imported rows); never now().
     */
    public static function from_comment( \WP_Comment $comment ): Context {
        $user_id     = (int) $comment->user_id;
        $occurred_at = (string) $comment->comment_date_gmt;

        if ( ! Dates::is_valid( $occurred_at ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'YayLoyalty: comment %d has no GMT date to evaluate at.', (int) $comment->comment_ID )
            );
        }

        $currency = get_woocommerce_currency();

        return new Context(
            $user_id > 0 ? $user_id : null,
            $occurred_at,
            $currency,
            self::to_base_factor( $currency, null ),
            [],
            0.0,
            0.0,
            [],
            self::user_roles( $user_id ),
            null,
            (int) $comment->comment_ID,
            [
                Triggers\ReviewHandler::EXTRA_VERIFIED_PURCHASE      => (bool) get_comment_meta( (int) $comment->comment_ID, 'verified', true ),
                Triggers\ReviewHandler::EXTRA_PRODUCT_ALREADY_EARNED => self::product_already_earned( $comment ),
            ],
            static fn() => self::customer_facts( $user_id, $occurred_at )
        );
    }

    /** One order line as the engine sees it; a refund line keeps its negative amounts. */
    public static function line_item( \WC_Order_Item_Product $item ): ContextLineItem {
        return new ContextLineItem(
            (int) $item->get_product_id(),
            $item->get_variation_id() ? (int) $item->get_variation_id() : null,
            self::category_ids( (int) $item->get_product_id() ),
            (int) $item->get_quantity(),
            (float) $item->get_subtotal(),
            (float) $item->get_total(),
            (float) $item->get_total_tax()
        );
    }

    /**
     * Null for a guest. The from_*() factories pass it as a loader, so it runs only when a history
     * condition is evaluated, which never happens in Lite.
     */
    public static function customer_facts( ?int $user_id, string $as_of, ?int $exclude_order_id = null ): ?CustomerFacts {
        if ( null === $user_id || $user_id < 1 ) {
            return null;
        }

        return CustomerFactsReader::read( $user_id, $as_of, $exclude_order_id );
    }

    /**
     * Whether another approved top-level review by the same user on the same
     * product has an earn row. Approved only: a reversed review no longer
     * holds the product's slot. False for a guest.
     */
    private static function product_already_earned( \WP_Comment $comment ): bool {
        $user_id = (int) $comment->user_id;

        if ( $user_id < 1 ) {
            return false;
        }

        $others = get_comments(
            [
                'user_id'         => $user_id,
                'post_id'         => (int) $comment->comment_post_ID,
                'parent'          => 0,
                'status'          => 'approve',
                'fields'          => 'ids',
                'comment__not_in' => [ (int) $comment->comment_ID ],
            ]
        );
        $ledger = new LedgerRepository();

        foreach ( (array) $others as $other_id ) {
            if ( null !== $ledger->find_earn_for_reference( ReferenceType::COMMENT, (int) $other_id ) ) {
                return true;
            }
        }

        return false;
    }

    /** For the product-page earn preview. A member's history is read only when a condition asks for it. */
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
        $now      = Dates::now();

        return new Context(
            $user_id,
            $now,
            $currency,
            self::to_base_factor( $currency, null ),
            [ $line ],
            $subtotal,
            0.0,
            [],
            self::user_roles( (int) $user_id ),
            null,
            null,
            [],
            self::facts_loader( $user_id, $now )
        );
    }

    /** For the storefront launcher's "Ways to earn": any shopper, no event (PointsEngine::advertised()). */
    public static function for_advert( string $at ): Context {
        $currency = get_woocommerce_currency();

        return Context::advert( $at, $currency, self::to_base_factor( $currency, null ) );
    }

    /** For the cart/checkout earn preview, with the payment method and addresses chosen so far. */
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
        $now          = Dates::now();

        return new Context(
            $user_id,
            $now,
            $currency,
            self::to_base_factor( $currency, null ),
            $line_items,
            (float) $cart->get_subtotal(),
            (float) $cart->get_discount_total(),
            $coupon_codes,
            self::user_roles( (int) $user_id ),
            null,
            null,
            self::checkout_inputs(),
            self::facts_loader( $user_id, $now )
        );
    }

    /**
     * What the checkout has chosen so far, as from_order() reads it off the order: '' for what is not chosen yet,
     * so the evaluator answers needs_order for a condition on it.
     *
     * @return array<string, string>
     */
    private static function checkout_inputs(): array {
        $session  = function_exists( 'WC' ) ? WC()->session : null;
        $customer = function_exists( 'WC' ) ? WC()->customer : null;
        $has      = $customer instanceof \WC_Customer;

        return [
            Context::EXTRA_PAYMENT_METHOD   => $session ? (string) $session->get( 'chosen_payment_method' ) : '',
            Context::EXTRA_BILLING_COUNTRY  => $has ? (string) $customer->get_billing_country() : '',
            Context::EXTRA_SHIPPING_COUNTRY => $has ? (string) $customer->get_shipping_country() : '',
        ];
    }

    /** The lazy customer_facts() loader for a member; none for a guest, whose history conditions answer no_customer. */
    private static function facts_loader( ?int $user_id, string $as_of ): ?\Closure {
        if ( null === $user_id || $user_id < 1 ) {
            return null;
        }

        return static fn() => self::customer_facts( $user_id, $as_of );
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

    private static function to_base_factor( string $currency, ?\WC_Order $order ): float {
        /**
         * Event currency → store base currency. Defaults to the inverse of CurrencyRate::rate(), which is
         * 1.0 unless a multi-currency integration answers yayloyalty_currency_rate.
         *
         * @param float          $rate
         * @param string         $currency
         * @param \WC_Order|null $order
         */
        $rate = (float) apply_filters( 'yayloyalty_context_to_base_factor', CurrencyRate::to_base( 1.0, $currency, $order ), $currency, $order );

        return $rate > 0 ? $rate : 1.0;
    }
}
