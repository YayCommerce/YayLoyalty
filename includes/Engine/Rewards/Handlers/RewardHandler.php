<?php
namespace YayLoyalty\Engine\Rewards\Handlers;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * One reward type's rules: what a claim costs and what it is worth. Unlike TriggerHandler there is no
 * is_eligible(): the customer already chose the reward, so a failed config gate is a denied() Result.
 * Never throws.
 */
interface RewardHandler {

    /** A RewardType constant. */
    public function type(): string;

    public function quote( Reward $reward, Context $ctx ): Result;
}
