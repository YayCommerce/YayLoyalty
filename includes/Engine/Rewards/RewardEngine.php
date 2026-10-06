<?php
namespace YayLoyalty\Engine\Rewards;

use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * What one reward costs and is worth for one customer right now. Writes nothing. No eligibility here:
 * the customer already chose the reward, and the Engine\Redemption gates run first.
 * No capability checks: permissions live at the HTTP boundary.
 */
final class RewardEngine {

    private Registry $registry;

    public function __construct( Registry $registry ) {
        $this->registry = $registry;
    }

    /**
     * Call only after the gates pass. Returns the type handler's quote unchanged.
     *
     * @throws \InvalidArgumentException When no handler owns the reward's type.
     */
    public function evaluate( Reward $reward, Context $ctx ): Result {
        return $this->registry->get( $reward->get_type() )->quote( $reward, $ctx );
    }
}
