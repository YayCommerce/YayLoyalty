<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * A yes/no that runs before the reward engine quotes and again before the
 * commit debits: program state, customer state, the reward's own
 * eligibility. Not a limit (those are Clamp's) and not a calculation.
 * Returns a reason so the quote can say why nothing is offered. Receives
 * only immutable inputs — a Gate can read, never write.
 */
interface Gate {

    /**
     * Null when the claim may proceed; otherwise a stable snake_case reason
     * (a ProgramGate::REASON_* or Rewards\Result::REASON_* constant) that
     * becomes Result::denied() in a quote and Outcome::skipped() at commit.
     * Must not throw for an ordinary "no".
     */
    public function check( Reward $reward, Context $ctx ): ?string;
}
