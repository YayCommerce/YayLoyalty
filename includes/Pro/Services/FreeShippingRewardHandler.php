<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Redemption\RewardCoupons;
use YayLoyalty\Engine\Redemption\RewardShipping;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Handlers\RewardHandler;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The `free_shipping` reward: a 0 coupon that marks it applied, while Lite's RewardShipping sets every rate to 0.
 * It is worth what the chosen rates cost before it, so it can't be applied before WooCommerce has calculated them.
 */
final class FreeShippingRewardHandler implements RewardHandler {

    public function type(): string {
        return RewardType::FREE_SHIPPING;
    }

    /**
     * Without a cart (the commit, which checks the order's own shipping) the cost alone, at value 0. With one, denied
     * with WooCommerce's minimum spend reason when `min_order` isn't met, REASON_NO_VALUE when the cart ships nothing
     * or has no rate yet, else worth the chosen rates' cost before the reward.
     */
    public function quote( Reward $reward, Context $ctx ): Result {
        $cost = (int) $reward->get_cost_points();
        $cart = $ctx->get_cart();

        if ( null === $cart ) {
            return Result::allowed( $reward, $cost, $cost, 0.0, $ctx->get_currency(), 0.0 );
        }

        $reason = RewardCoupons::probe( $reward->get_coupon_code(), RewardCoupons::in_currency( $this->coupon_props( $reward ), $ctx->get_currency_rate() ), $cart )['reason'];

        // The 0 coupon itself takes nothing off, so the probe's no_value is expected; any other reason stands.
        if ( '' !== $reason && RewardCoupons::REASON_NO_VALUE !== $reason ) {
            return Result::denied( $reason );
        }

        // Shipping can be freed once: a free-shipping reward picked after another has nothing left to free.
        $value = $cart->needs_shipping() && ! self::is_picked_after_another( $reward ) ? RewardShipping::cart_value() : 0.0;

        if ( $value <= 0 ) {
            return Result::denied( RewardCoupons::REASON_NO_VALUE );
        }

        return Result::allowed( $reward, $cost, $cost, $value, $ctx->get_currency(), 0.0 );
    }

    /** Whether the shopper picked another free-shipping reward before this one. */
    private static function is_picked_after_another( Reward $reward ): bool {
        $rewards = new RewardRepository();

        foreach ( ( new SessionIntent() )->rewards() as $reward_id ) {
            if ( (int) $reward->get_id() === $reward_id ) {
                return false;
            }

            $earlier = $rewards->find_reward( $reward_id );

            if ( null !== $earlier && RewardType::FREE_SHIPPING === $earlier->get_type() ) {
                return true;
            }
        }

        return false;
    }

    /** A `fixed_cart` coupon at 0 that frees the shipping, with `config.min_order` as its minimum spend. */
    public function coupon_props( Reward $reward ): array {
        $config = $reward->get_config();

        return [
            'discount_type'      => 'fixed_cart',
            'amount'             => 0.0,
            'minimum_amount'     => isset( $config['min_order'] ) ? (float) $config['min_order'] : null,
            'exclude_sale_items' => false,
            'max_discount'       => null,
            'free_shipping'      => true,
        ];
    }
}
