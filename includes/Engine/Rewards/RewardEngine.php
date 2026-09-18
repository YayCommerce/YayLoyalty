<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a customer may claim one reward right now, at what cost and for
 * what value. The customer already chose the reward, so there is no
 * candidate scan: generic gates first, then the type's own quote. Writes
 * nothing. Pure: reads the Reward and the Context only, scored at $ctx->at.
 * No capability checks: permissions live at the HTTP boundary.
 *
 * Scaffold: the gates below are TODOs in evaluation order and the Lite
 * handler denies `not_implemented`, so this always denies until implemented.
 */
final class RewardEngine {

    /** @var Registry */
    private $registry;

    public function __construct( Registry $registry ) {
        $this->registry = $registry;
    }

    /**
     * @throws \InvalidArgumentException When no handler owns the reward's type.
     */
    public function evaluate( Reward $reward, Context $ctx ): Result {
        $handler = $this->registry->get( $reward->get_type() );

        // TODO gate 1, liveness: a reward not live at the context's time denies REASON_NOT_LIVE.
        // TODO gate 2, balance: a balance below the cost denies REASON_INSUFFICIENT_BALANCE. The cost
        //   is the reward's cost_points for fixed-cost types and the context's points_requested for
        //   flexible_discount.
        // TODO gate 3, conditions: a false from the yayloyalty_reward_conditions_match filter (default
        //   true, args reward and context) denies REASON_CONDITIONS_NOT_MET. Stays a filter until a
        //   reward conditions evaluator exists; redemption_count needs the redemptions table.

        return $handler->quote( $reward, $ctx );
    }
}
