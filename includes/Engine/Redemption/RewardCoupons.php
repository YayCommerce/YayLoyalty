<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Points\Context as EarnContext;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * A reward's virtual coupon, `yayloyalty-reward-{id}`, from the props its RewardHandler describes. Never a
 * `shop_coupon` post. Every coupon here is built with set_props() and never loaded by code: loading
 * `new \WC_Coupon( $code )` would re-enter `woocommerce_get_shop_coupon_data`, which is what calls in here.
 */
final class RewardCoupons {

    /** Denial reasons: WooCommerce's minimum spend, its sale-item exclusion, or nothing left to discount. */
    public const REASON_MIN_ORDER  = 'min_order';
    public const REASON_SALE_ITEMS = 'sale_items';
    public const REASON_NO_VALUE   = 'no_value';

    /**
     * An unsaved virtual coupon for the reward.
     *
     * @param array $props RewardHandler::coupon_props().
     */
    public static function coupon( int $reward_id, array $props ): \WC_Coupon {
        $coupon = new \WC_Coupon();

        $coupon->set_code( CouponProvider::reward_code( $reward_id ) );
        $coupon->set_virtual( true );
        $coupon->set_props( self::coupon_fields( $props ) );

        return $coupon;
    }

    /**
     * Whether the coupon can go on this cart, and what it alone takes off after every applied coupon that is not
     * a loyalty code. `reason` is '' when usable; `value` is uncapped.
     *
     * @param array $props RewardHandler::coupon_props().
     * @return array{reason: string, value: float}
     */
    public static function probe( int $reward_id, array $props, \WC_Cart $cart ): array {
        $coupon    = self::coupon( $reward_id, $props );
        $discounts = new \WC_Discounts( $cart );
        $error     = 0;
        $subtotal  = self::displayed_subtotal( $cart );

        // is_coupon_valid() answers a WP_Error without WooCommerce's numeric code; this filter sees it.
        $capture = static function ( $message, $code, $failed ) use ( $coupon, &$error ) {
            if ( $failed === $coupon ) {
                $error = (int) $code;
            }

            return $message;
        };

        // The minimum spend reads the cart's stored subtotal, which is 0 inside `woocommerce_before_calculate_totals`.
        $minimum = static function ( $is_not_met, $checked ) use ( $coupon, $subtotal ) {
            return $checked === $coupon ? (float) $coupon->get_minimum_amount() > $subtotal : $is_not_met;
        };

        add_filter( 'woocommerce_coupon_error', $capture, 10, 3 );
        add_filter( 'woocommerce_coupon_validate_minimum_amount', $minimum, 10, 2 );
        $valid = $discounts->is_coupon_valid( $coupon );
        remove_filter( 'woocommerce_coupon_validate_minimum_amount', $minimum, 10 );
        remove_filter( 'woocommerce_coupon_error', $capture, 10 );

        if ( is_wp_error( $valid ) ) {
            return [
                'reason' => self::reason_for( $error ),
                'value'  => 0.0,
            ];
        }

        $merchant = [];

        foreach ( $cart->get_applied_coupons() as $code ) {
            if ( ! EarnContext::is_loyalty_code( (string) $code ) ) {
                $merchant[] = new \WC_Coupon( $code );
            }
        }

        foreach ( ContextFactory::in_cart_order( $merchant ) as $applied ) {
            $discounts->apply_coupon( $applied );
        }

        $discounts->apply_coupon( $coupon, false );

        $value = (float) ( $discounts->get_discounts_by_coupon()[ $coupon->get_code() ] ?? 0 );

        return [
            'reason' => $value > 0 ? '' : self::REASON_NO_VALUE,
            'value'  => $value > 0 ? $value : 0.0,
        ];
    }

    /**
     * The props with the cap applied: `fixed_cart` at `max_discount` when the probe gives more than the cap,
     * unchanged otherwise.
     *
     * @param array $props RewardHandler::coupon_props().
     * @return array
     */
    public static function capped( int $reward_id, array $props, \WC_Cart $cart ): array {
        $cap = $props['max_discount'] ?? null;

        if ( null === $cap ) {
            return $props;
        }

        $probe = self::probe( $reward_id, $props, $cart );

        if ( $probe['value'] > (float) $cap ) {
            $props['discount_type'] = 'fixed_cart';
            $props['amount']        = (float) $cap;
        }

        return $props;
    }

    /**
     * The props in the currency the cart is charged in. A reward's money config is in the base currency, so
     * `amount` (for `fixed_cart` only; a percent stays a percent), `minimum_amount` and `max_discount` are
     * multiplied by $rate when set, then floored to the currency's decimals, as Clamp floors a discount and
     * LauncherCopy::from_base() shows them.
     *
     * @param array $props RewardHandler::coupon_props().
     * @param float $rate  Units of the charged currency per 1 base unit (CurrencyRate::rate()).
     * @return array
     */
    public static function in_currency( array $props, float $rate ): array {
        if ( 1.0 === $rate || $rate <= 0 ) {
            return $props;
        }

        $fields = [ 'minimum_amount', 'max_discount' ];

        if ( 'fixed_cart' === ( $props['discount_type'] ?? '' ) ) {
            $fields[] = 'amount';
        }

        foreach ( $fields as $field ) {
            if ( isset( $props[ $field ] ) ) {
                $props[ $field ] = PointsMath::floor_to_decimals( (float) $props[ $field ] * $rate, wc_get_price_decimals() );
            }
        }

        return $props;
    }

    /**
     * The `woocommerce_get_shop_coupon_data` array for the props: stacks with every other coupon, no free
     * shipping, no usage limit, no expiry.
     *
     * @param array $props RewardHandler::coupon_props(), capped or not.
     * @return array<string, mixed>
     */
    public static function data( array $props ): array {
        return self::coupon_fields( $props ) + [
            'individual_use' => false,
            'free_shipping'  => false,
            'usage_limit'    => 0,
        ];
    }

    /**
     * The subtotal WooCommerce checks a minimum spend against (WC_Cart::get_displayed_subtotal()), rebuilt from
     * the items, since the stored one is reset while totals are calculated.
     */
    public static function displayed_subtotal( \WC_Cart $cart ): float {
        $including_tax = $cart->display_prices_including_tax();
        $subtotal      = 0.0;

        foreach ( $cart->get_cart() as $item ) {
            if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
                $args      = [ 'qty' => (float) $item['quantity'] ];
                $subtotal += (float) ( $including_tax ? wc_get_price_including_tax( $item['data'], $args ) : wc_get_price_excluding_tax( $item['data'], $args ) );
            }
        }

        return $subtotal;
    }

    /** The WC_Coupon props a reward sets; `max_discount` is not one. */
    private static function coupon_fields( array $props ): array {
        return [
            'discount_type'      => (string) $props['discount_type'],
            'amount'             => (float) $props['amount'],
            'minimum_amount'     => null === ( $props['minimum_amount'] ?? null ) ? '' : (float) $props['minimum_amount'],
            'exclude_sale_items' => ! empty( $props['exclude_sale_items'] ),
        ];
    }

    /** WooCommerce's coupon error code as a REASON_* constant. */
    private static function reason_for( int $error ): string {
        switch ( $error ) {
            case \WC_Coupon::E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET:
                return self::REASON_MIN_ORDER;

            case \WC_Coupon::E_WC_COUPON_NOT_VALID_SALE_ITEMS:
                return self::REASON_SALE_ITEMS;
        }

        return self::REASON_NO_VALUE;
    }
}
