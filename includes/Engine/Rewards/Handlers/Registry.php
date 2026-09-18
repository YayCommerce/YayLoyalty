<?php
namespace YayLoyalty\Engine\Rewards\Handlers;

defined( 'ABSPATH' ) || exit;

/** Reward type → handler. with_lite_handlers() is the seam Pro's handlers attach through. */
final class Registry {

    /** @var array<string, RewardHandler> */
    private $handlers = [];

    public function register( RewardHandler $handler ): void {
        $this->handlers[ $handler->type() ] = $handler;
    }

    public function has( string $type ): bool {
        return isset( $this->handlers[ $type ] );
    }

    /**
     * @throws \InvalidArgumentException When no handler owns the type.
     */
    public function get( string $type ): RewardHandler {
        if ( ! $this->has( $type ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'YayLoyalty: no reward handler registered for "%s".', $type )
            );
        }

        return $this->handlers[ $type ];
    }

    /** The Lite handlers, then whatever the build adds. */
    public static function with_lite_handlers(): self {
        $registry = new self();

        $registry->register( new FlexibleDiscountHandler() );

        /**
         * Lets Pro register handlers for its own reward types. Internal seam, not a public API.
         *
         * @param Registry $registry
         */
        do_action( 'yayloyalty_register_reward_handlers', $registry );

        return $registry;
    }
}
