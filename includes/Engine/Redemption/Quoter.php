<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Engine\Rewards\Result;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The pure half of redemption: gates in order, then the engine. Holds
 * nothing that can write — no repository, no session, no writer; a gate
 * may read settings, never more — so the quote path cannot write by
 * construction. One instance serves the
 * cart quote and the commit's re-check under the lock, so the two moments
 * cannot disagree. Implemented.
 */
final class Quoter {

    private RewardEngine $engine;

    /** @var Gate[] In check order, cheap first; the first reason wins. */
    private array $gates;

    /**
     * @param Gate[] $gates
     */
    public function __construct( RewardEngine $engine, array $gates ) {
        $this->engine = $engine;
        $this->gates  = array_values( $gates );
    }

    /**
     * Postconditions: Result::denied( reason ) from the first gate that
     * objects, later gates not called and the engine not reached; otherwise
     * the engine's quote unchanged. Same inputs, same Result, any number of
     * times.
     *
     * @throws \InvalidArgumentException When no handler owns the reward's type.
     */
    public function quote( Reward $reward, Context $ctx ): Result {
        $reason = $this->blocked_reason( $reward, $ctx );

        if ( null !== $reason ) {
            return Result::denied( $reason );
        }

        return $this->engine->evaluate( $reward, $ctx );
    }

    /** Null when every gate passes; otherwise the first gate's reason. */
    public function blocked_reason( Reward $reward, Context $ctx ): ?string {
        foreach ( $this->gates as $gate ) {
            $reason = $gate->check( $reward, $ctx );

            if ( null !== $reason && '' !== $reason ) {
                return $reason;
            }
        }

        return null;
    }
}
