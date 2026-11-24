<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Free shipping for a reward whose coupon props carry `free_shipping`: while its coupon is applied, every rate of
 * every package costs 0. Each rate keeps its original cost and taxes in its meta, and is restored from them before
 * anything else, so a rate object this filter already saw is never zeroed twice nor left at 0. The meta reaches the
 * order's shipping items, which order_value() reads at commit.
 */
final class RewardShipping {

    /** Rate and shipping item meta: the cost and taxes before the reward, and the reward that freed it (0 when none). */
    public const META_COST   = '_yayloyalty_original_cost';
    public const META_TAXES  = '_yayloyalty_original_taxes';
    public const META_REWARD = '_yayloyalty_free_shipping_reward';

    /** WC session key: the chosen rates' total before the reward, on the basis prices are entered, from the last calculation. */
    public const SESSION_KEY = 'yayloyalty_shipping_before_reward';

    private RedemptionService $service;

    public function __construct( RedemptionService $service ) {
        $this->service = $service;
    }

    public function register(): void {
        // After other plugins' rate changes at the default priority, so the original is what the shopper would pay.
        add_filter( 'woocommerce_shipping_packages', [ $this, 'adjust_packages' ], 100 );
        add_action( 'woocommerce_checkout_create_order_shipping_item', [ $this, 'drop_unused_meta' ] );
    }

    /**
     * Keeps the meta only on a shipping item a reward freed; every other order would carry it for nothing.
     *
     * @param \WC_Order_Item_Shipping $item
     */
    public function drop_unused_meta( $item ): void {
        if ( $item instanceof \WC_Order_Item_Shipping && 0 === (int) $item->get_meta( self::META_REWARD ) ) {
            foreach ( [ self::META_COST, self::META_TAXES, self::META_REWARD ] as $key ) {
                $item->delete_meta_data( $key );
            }
        }
    }

    /**
     * Restores every rate from its stored original, then sets it to 0 while a free-shipping reward is applied, and
     * records the chosen rates' original total for the reward's quote.
     *
     * @param mixed $packages
     * @return mixed
     */
    public function adjust_packages( $packages ) {
        if ( ! is_array( $packages ) ) {
            return $packages;
        }

        $reward = $this->applied_reward();
        $chosen = function_exists( 'WC' ) && WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [];
        $before = 0.0;

        foreach ( $packages as $index => $package ) {
            $rates = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : [];
            // A choice that is no longer offered (the address changed) falls back as WooCommerce will: its first rate.
            $chosen_id = isset( $chosen[ $index ] ) && isset( $rates[ $chosen[ $index ] ] ) ? $chosen[ $index ] : (string) array_key_first( $rates );

            foreach ( $rates as $rate_id => $rate ) {
                if ( ! $rate instanceof \WC_Shipping_Rate ) {
                    continue;
                }

                list( $cost, $taxes ) = self::restore( $rate );

                if ( (string) $rate_id === (string) $chosen_id ) {
                    $before += self::on_entered_basis( $cost, $taxes, wc_prices_include_tax() );
                }

                $rate->add_meta_data( self::META_REWARD, null === $reward ? 0 : (int) $reward->get_id() );

                if ( null !== $reward ) {
                    $rate->set_cost( 0 );
                    $rate->set_taxes( array_map( '__return_zero', $taxes ) );
                }
            }
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( self::SESSION_KEY, $before );
        }

        return $packages;
    }

    /** The chosen rates' total before any reward, from the last shipping calculation; 0 before rates exist. */
    public static function cart_value(): float {
        return function_exists( 'WC' ) && WC()->session ? (float) WC()->session->get( self::SESSION_KEY, 0 ) : 0.0;
    }

    /**
     * What the reward took off $order's shipping: each shipping item it freed, its original cost less what the item
     * still charges, taxes included when the order's prices include tax. 0 when it freed none.
     */
    public static function order_value( \WC_Order $order, int $reward_id ): float {
        $includes_tax = (bool) $order->get_prices_include_tax();
        $value        = 0.0;

        foreach ( $order->get_items( 'shipping' ) as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Shipping || $reward_id !== (int) $item->get_meta( self::META_REWARD ) ) {
                continue;
            }

            $original = self::on_entered_basis( (float) $item->get_meta( self::META_COST ), (array) $item->get_meta( self::META_TAXES ), $includes_tax );
            $charged  = (float) $item->get_total() + ( $includes_tax ? (float) $item->get_total_tax() : 0.0 );
            $value   += max( 0.0, $original - $charged );
        }

        return $value;
    }

    /**
     * The rate's original cost and taxes: stored on the first sight, put back on every later one.
     *
     * @return array{0: float, 1: array}
     */
    private static function restore( \WC_Shipping_Rate $rate ): array {
        $meta = $rate->get_meta_data();

        if ( ! array_key_exists( self::META_COST, $meta ) ) {
            $rate->add_meta_data( self::META_COST, (string) $rate->get_cost() );
            $rate->add_meta_data( self::META_TAXES, $rate->get_taxes() );

            return [ (float) $rate->get_cost(), (array) $rate->get_taxes() ];
        }

        $cost  = (float) $meta[ self::META_COST ];
        $taxes = (array) ( $meta[ self::META_TAXES ] ?? [] );

        $rate->set_cost( $cost );
        $rate->set_taxes( $taxes );

        return [ $cost, $taxes ];
    }

    private static function on_entered_basis( float $cost, array $taxes, bool $includes_tax ): float {
        return $cost + ( $includes_tax ? (float) array_sum( array_map( 'floatval', $taxes ) ) : 0.0 );
    }

    /** The first applied reward whose props carry `free_shipping`, null when none is or there is no cart. */
    private function applied_reward(): ?Reward {
        $cart = RedemptionService::cart();

        foreach ( null === $cart ? [] : $this->service->applied_rewards( $cart ) as $reward ) {
            if ( ! empty( $this->service->coupon_props_of( $reward )['free_shipping'] ) ) {
                return $reward;
            }
        }

        return null;
    }
}
