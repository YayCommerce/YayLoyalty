<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The registration event family: one award per user, keyed
 * "earn:{id}:user:{id}:signup", so a re-fired user_register is a duplicate.
 * No preview and no pending: a signup has nothing to stage.
 */
final class SignupListener {

    private EarningService $service;

    public function __construct( EarningService $service ) {
        $this->service = $service;
    }

    /** Attaches user_register. woocommerce_created_customer is not attached: it always fires after user_register for the same signup and would evaluate it twice. */
    public function register(): void {
        add_action( 'user_register', [ $this, 'on_user_register' ], 10, 1 );
    }

    /**
     * `user_register` adapter. Temporary: on_registered()'s signature already
     * matches this hook, so once it is implemented register() attaches it
     * directly and this adapter is deleted.
     */
    public function on_user_register( int $user_id ): void {
        Log::debug( 'SignupListener', 'user #' . $user_id . ' registered' );

        try {
            $this->on_registered( $user_id );
        } catch ( \RuntimeException $e ) {
            // TODO(step-3): remove this adapter once on_registered() is implemented; register() then attaches on_registered directly.
            Log::warning( 'SignupListener', 'award skipped for user #' . $user_id . ': ' . $e->getMessage() );
        }
    }

    public function on_registered( int $user_id ): void {
        // TODO(F01-4): ContextFactory::from_user( user_id, user_registered ); service->award( SIGNUP, ctx ).
    }
}
