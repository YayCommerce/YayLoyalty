<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The reward-level gate: is the reward live at the claim's time, can the
 * customer afford a fixed cost, do the reward's conditions (tier, lifetime
 * points, redemption count) hold. First failing rule wins, in that order.
 * Balance for `flexible_discount` is not a gate — Clamp reports it as a
 * ceiling so the UI can explain — only fixed-cost types fail here.
 * Conditions go through the `yayloyalty_reward_conditions_match` filter
 * (default true; Pro attaches an evaluator over $ctx->get_facts()).
 * Scaffold: stub.
 */
final class RewardGate implements Gate {

    public function check( Reward $reward, Context $ctx ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(F08): ! reward->is_live_at( ctx->get_at() ) → Result::REASON_NOT_LIVE; cost_points set and > ctx balance → REASON_INSUFFICIENT_BALANCE; apply_filters( yayloyalty_reward_conditions_match, true, reward, ctx ) false → REASON_CONDITIONS_NOT_MET; else null.
        throw new \RuntimeException( 'Not implemented' );
    }
}
