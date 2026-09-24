<?php
namespace YayLoyalty\Engine\Rewards\Handlers;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Points → cart discount at the customer's chosen amount. The maths is
 * Clamp::apply(), the single source of every limit; this handler only
 * unpacks the Context for it. Scaffold: stub.
 */
final class FlexibleDiscountHandler implements RewardHandler {

    public function type(): string {
        return RewardType::FLEXIBLE_DISCOUNT;
    }

    /**
     * Precondition: $ctx->get_points_requested() is the session intent (null
     * reads as 0). Postcondition: exactly Clamp::apply( $reward, requested,
     * $ctx->get_balance(), $ctx->get_cart_subtotal(), $ctx->get_currency(),
     * wc_get_price_decimals() ).
     */
    public function quote( Reward $reward, Context $ctx ): Result { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): hand the reward, the requested points cast to int, the balance, the cart subtotal and the currency from the Context, plus wc_get_price_decimals(), to Clamp::apply and return its Result.
        throw new \RuntimeException( 'Not implemented' );
    }
}
