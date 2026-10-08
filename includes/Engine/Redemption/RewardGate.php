<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The reward-level gate: is the reward live at the claim's time, can the
 * customer afford a fixed cost, do the reward's conditions (role, tier,
 * lifetime points) hold. First failing rule wins, in that order.
 * Balance for `flexible_discount` is not a gate — Clamp reports it as a
 * ceiling so the UI can explain — only fixed-cost types fail here.
 * Conditions go through the `yayloyalty_reward_conditions_match` filter
 * (default true; Pro attaches an evaluator over $ctx->get_facts()).
 */
final class RewardGate implements Gate {

    public function check( Reward $reward, Context $ctx ): ?string {
        if ( ! $reward->is_live_at( $ctx->get_at() ) ) {
            return Result::REASON_NOT_LIVE;
        }

        $cost = $reward->get_cost_points();

        if ( null !== $cost && $cost > $ctx->get_balance() ) {
            return Result::REASON_INSUFFICIENT_BALANCE;
        }

        /**
         * Whether the reward's conditions hold for this claim. Internal seam for Pro, not a public API.
         *
         * @param bool    $matches
         * @param Reward  $reward
         * @param Context $ctx
         */
        if ( ! apply_filters( 'yayloyalty_reward_conditions_match', true, $reward, $ctx ) ) {
            return Result::REASON_CONDITIONS_NOT_MET;
        }

        return null;
    }
}
