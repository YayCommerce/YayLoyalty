<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Redemption\RewardCartLines;
use YayLoyalty\Engine\Redemption\RewardCoupons;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Handlers\RewardHandler;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The `free_product` reward: a 0 coupon that marks it applied, while Lite's RewardCartLines adds `config.qty` of the
 * product as its own line at 0. It is worth the product's price × qty as the shop sells it now.
 */
final class FreeProductRewardHandler implements RewardHandler {

    public function type(): string {
        return RewardType::FREE_PRODUCT;
    }

    /**
     * Without a cart (the commit, which checks the order's own line) the cost alone, at value 0. With one, denied
     * REASON_NO_VALUE when the product (or its variation) is gone, can't be bought or costs nothing;
     * REASON_SOLD_INDIVIDUALLY when it sells one per order and the shopper already has it; REASON_OUT_OF_STOCK when
     * the stock can't cover `qty` on top of the shopper's own copies; else worth its price × qty.
     */
    public function quote( Reward $reward, Context $ctx ): Result {
        $cost = (int) $reward->get_cost_points();
        $cart = $ctx->get_cart();

        if ( null === $cart ) {
            return Result::allowed( $reward, $cost, $cost, 0.0, $ctx->get_currency(), 0.0 );
        }

        $item    = $this->coupon_props( $reward )['free_item'];
        $product = self::product_of( $item );
        $qty     = null === $product ? 0 : RewardCartLines::quantity_of( $product, $item['qty'] );
        $value   = null === $product ? 0.0 : (float) $product->get_price() * $qty;

        if ( $value <= 0 ) {
            return Result::denied( RewardCoupons::REASON_NO_VALUE );
        }

        $own = self::shopper_quantity( $cart, $product );

        if ( $product->is_sold_individually() && $own > 0 ) {
            return Result::denied( RewardCoupons::REASON_SOLD_INDIVIDUALLY );
        }

        if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $own + $qty ) ) {
            return Result::denied( RewardCoupons::REASON_OUT_OF_STOCK );
        }

        return Result::allowed( $reward, $cost, $cost, $value, $ctx->get_currency(), 0.0 );
    }

    /** How many of $product the shopper's own lines hold: every line but the reward lines, matched as WooCommerce matches stock. */
    private static function shopper_quantity( \WC_Cart $cart, \WC_Product $product ): int {
        $quantity = 0;

        foreach ( $cart->get_cart() as $line ) {
            if ( ! RewardCartLines::is_reward_line( $line ) && isset( $line['data'] ) && $line['data'] instanceof \WC_Product && $line['data']->get_stock_managed_by_id() === $product->get_stock_managed_by_id() ) {
                $quantity += (int) $line['quantity'];
            }
        }

        return $quantity;
    }

    /** A `fixed_cart` coupon at 0, carrying the product, variation and quantity of the free line. */
    public function coupon_props( Reward $reward ): array {
        $config       = $reward->get_config();
        $variation_id = (int) ( $config['variation_id'] ?? 0 );
        $variation    = $variation_id > 0 ? wc_get_product( $variation_id ) : null;
        // The cart line takes the variation's own parent, so the reward must name the same one or the line never matches.
        $product_id = $variation instanceof \WC_Product_Variation ? $variation->get_parent_id() : (int) ( $config['product_id'] ?? 0 );

        return [
            'discount_type'      => 'fixed_cart',
            'amount'             => 0.0,
            'minimum_amount'     => null,
            'exclude_sale_items' => false,
            'max_discount'       => null,
            'free_item'          => [
                'product_id'   => (int) $product_id,
                'variation_id' => $variation_id > 0 ? $variation_id : null,
                'qty'          => max( 1, (int) ( $config['qty'] ?? 1 ) ),
            ],
        ];
    }

    /**
     * The product the line would add, when it can be added now: it exists, can be bought, and is not a variable parent
     * without a variation nor a variation with an "Any" attribute the line couldn't pick. Stock is quote()'s to judge.
     *
     * @param array{product_id: int, variation_id: ?int, qty: int} $item
     */
    private static function product_of( array $item ): ?\WC_Product {
        $product = wc_get_product( null === $item['variation_id'] ? $item['product_id'] : $item['variation_id'] );

        if ( ! $product instanceof \WC_Product || $product->is_type( 'variable' ) || ! $product->is_purchasable() ) {
            return null;
        }

        if ( $product instanceof \WC_Product_Variation && in_array( '', $product->get_variation_attributes(), true ) ) {
            return null;
        }

        return $product;
    }
}
