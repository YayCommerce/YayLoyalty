<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The registration event family: one award per user, keyed
 * "earn:{id}:user:{id}:signup", so a re-fired user_register is a duplicate.
 * No pending: a signup has nothing to stage.
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

    /** `user_register` adapter: the catch keeps a scoring failure from breaking the registration. */
    public function on_user_register( int $user_id ): void {
        Log::debug( 'SignupListener', 'user #' . $user_id . ' registered' );

        try {
            $this->on_registered( $user_id );
        } catch ( \Throwable $e ) { // A listener must never break a registration.
            Log::error( 'SignupListener', 'signup failed for user #' . $user_id . ': ' . $e->getMessage() );
        }
    }

    /**
     * Logs which signup campaign the user would earn from, how many points,
     * or why none. Writes nothing. Scored at the registration time.
     */
    public function on_registered( int $user_id ): void {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $result = $this->service->preview( CampaignTrigger::SIGNUP, ContextFactory::from_user( $user_id, (string) $user->user_registered ) );

        PreviewLog::write( 'SignupListener', 'user #' . $user_id . ' signup', $result );
        // TODO(F01-4): replace the preview with service->award( SIGNUP, ctx ).
    }
}
