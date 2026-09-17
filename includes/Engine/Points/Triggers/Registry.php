<?php
namespace YayLoyalty\Engine\Points\Triggers;

use YayLoyalty\Engine\Points\BaseCalculator;
use YayLoyalty\Repositories\CampaignRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Trigger name → handler.
 *
 * with_lite_handlers() is the construction site for the 1.0 set and the seam
 * Pro's future handlers (birthday, milestones…) will attach through, so the
 * engine never needs to know which build it is in.
 */
final class Registry {

    /** @var array<string, TriggerHandler> */
    private $handlers = [];

    public function register( TriggerHandler $handler ): void {
        $this->handlers[ $handler->trigger() ] = $handler;
    }

    public function has( string $trigger ): bool {
        return isset( $this->handlers[ $trigger ] );
    }

    /**
     * @throws \InvalidArgumentException When no handler owns the trigger.
     */
    public function get( string $trigger ): TriggerHandler {
        if ( ! $this->has( $trigger ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'YayLoyalty: no trigger handler registered for "%s".', $trigger )
            );
        }

        return $this->handlers[ $trigger ];
    }

    /**
     * The Lite handlers, then whatever the build adds.
     */
    public static function with_lite_handlers( CampaignRepository $campaigns ): self {
        $registry = new self();

        $registry->register( new PurchaseHandler( $campaigns, new BaseCalculator() ) );
        $registry->register( new SignupHandler() );
        $registry->register( new ReviewHandler() );

        /**
         * Fires so Pro can register handlers for its own triggers.
         *
         * Internal seam for first-party Pro code, not a documented
         * third-party API.
         *
         * @param Registry $registry
         */
        do_action( 'yayloyalty_register_trigger_handlers', $registry );

        return $registry;
    }
}
