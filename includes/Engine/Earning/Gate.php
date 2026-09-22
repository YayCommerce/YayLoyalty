<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\Context;

defined( 'ABSPATH' ) || exit;

/**
 * A yes/no that sits between evaluation and award and applies to both
 * moments: program state, customer state, policy. Not a campaign
 * condition (those are the engine's) and not a calculation. Returns a
 * reason so the outcome can say why nothing was staged or written.
 */
interface Gate {

    /**
     * Null when the event may proceed; otherwise a stable snake_case reason
     * (see ProgramGate::REASON_*) that becomes Result::none() in a preview
     * and Outcome::skipped() at award. Must not throw for an ordinary "no".
     */
    public function check( string $trigger, Context $ctx ): ?string;
}
