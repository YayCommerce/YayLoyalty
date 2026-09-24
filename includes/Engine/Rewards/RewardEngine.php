<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * What one reward costs and is worth for one customer right now. The
 * customer already chose the reward, so there is no candidate scan and no
 * eligibility here: liveness, balance vs a fixed cost, conditions and
 * program state are Engine\Redemption gates that run before this is
 * called. Writes nothing. Pure: reads the Reward and the Context only.
 * No capability checks: permissions live at the HTTP boundary.
 */
final class RewardEngine {

    private Registry $registry;

    public function __construct( Registry $registry ) {
        $this->registry = $registry;
    }

    /**
     * Precondition: the gates said yes. Postcondition: the type handler's
     * quote, unchanged — a Result::allowed() carrying the clamped points,
     * or a handler-specific denial.
     *
     * @throws \InvalidArgumentException When no handler owns the reward's type.
     */
    public function evaluate( Reward $reward, Context $ctx ): Result {
        return $this->registry->get( $reward->get_type() )->quote( $reward, $ctx );
    }
}
