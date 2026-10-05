<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\Context;

defined( 'ABSPATH' ) || exit;

/**
 * A yes/no on program state, customer state or policy, checked before both preview and award.
 * Campaign conditions belong to the engine, not here.
 */
interface Gate {

    /**
     * Null when the event may proceed; otherwise a stable snake_case reason
     * (see ProgramGate::REASON_*) that becomes Result::none() in a preview
     * and Outcome::skipped() at award. Must not throw for an ordinary "no".
     */
    public function check( string $trigger, Context $ctx ): ?string;
}
