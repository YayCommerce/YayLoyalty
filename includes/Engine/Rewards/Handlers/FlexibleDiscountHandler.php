<?php
namespace YayLoyalty\Engine\Rewards\Handlers;

use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Points → cart discount at the customer's chosen amount. The maths is
 * Clamp::apply(), the single source of every limit; this handler only
 * unpacks the Context for it.
 */
final class FlexibleDiscountHandler implements RewardHandler {

    public function type(): string {
        return RewardType::FLEXIBLE_DISCOUNT;
    }

    /**
     * Precondition: $ctx->get_points_requested() is the session intent (null
     * reads as 0). Postcondition: exactly Clamp::apply( $reward, requested,
     * $ctx->get_balance(), $ctx->get_cart_subtotal(), $ctx->get_currency(),
     * wc_get_price_decimals(), $ctx->get_currency_rate() ), so the discount is in the charged currency.
     */
    public function quote( Reward $reward, Context $ctx ): Result {
        return Clamp::apply( $reward, (int) $ctx->get_points_requested(), $ctx->get_balance(), $ctx->get_cart_subtotal(), $ctx->get_currency(), wc_get_price_decimals(), $ctx->get_currency_rate() );
    }

    /** The slider's discount is the coupon CouponProvider builds from the quote under this reward's code, not a reward coupon. */
    public function coupon_props( Reward $reward ): ?array {
        return null;
    }
}
