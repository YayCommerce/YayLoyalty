<?php
namespace YayLoyalty\Engine\Rewards\Handlers;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * One reward type's rules: what a claim costs and what it is worth. No
 * is_eligible() unlike TriggerHandler: the customer already chose the
 * reward, so there is no fall-through to a next candidate — a config gate
 * that fails is a denied() Result from quote(). Never throws.
 */
interface RewardHandler {

    /** A RewardType constant. */
    public function type(): string;

    public function quote( Reward $reward, Context $ctx ): Result;
}
