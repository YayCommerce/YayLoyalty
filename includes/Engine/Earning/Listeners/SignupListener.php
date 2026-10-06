<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Awards the signup campaign once per user, keyed "earn:{id}:user:{id}:signup".
 * Only storefront self-registration earns, not an account an admin, an import, WP-CLI or cron creates.
 */
final class SignupListener {

    private EarningService $service;

    public function __construct( EarningService $service ) {
        $this->service = $service;
    }

    /** Not woocommerce_created_customer: it fires after user_register for the same signup and would evaluate it twice. */
    public function register(): void {
        add_action( 'user_register', [ $this, 'on_user_register' ], 10, 1 );
    }

    public function on_user_register( int $user_id ): void {
        Log::debug( 'SignupListener', 'user #' . $user_id . ' registered' );

        ListenerGuard::run( 'SignupListener', 'signup failed for user #' . $user_id, fn() => $this->on_registered( $user_id ) );
    }

    /** Awards the signup campaign for a self-registered user, scored at the registration time. */
    public function on_registered( int $user_id ): void {
        $subject = 'user #' . $user_id . ' signup';

        if ( ! self::is_self_registration() ) {
            Log::debug( 'SignupListener', $subject . ': not_self_registration' );
            return;
        }

        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        $outcome = $this->service->award( CampaignTrigger::SIGNUP, ContextFactory::from_user( $user_id, (string) $user->user_registered ) );

        PreviewLog::write_outcome( 'SignupListener', $subject, $outcome );
    }

    /**
     * A visitor registering themself: at user_register nobody is logged in
     * yet, while admin screens, imports and authenticated REST calls have an
     * acting user. Background runners have none either, so they are excluded
     * by name: WP-CLI, cron, and Action Scheduler's async (loopback) runner.
     */
    public static function is_self_registration(): bool {
        return 0 === get_current_user_id()
            && ! ( defined( 'WP_CLI' ) && WP_CLI )
            && ! wp_doing_cron()
            && ! doing_action( 'action_scheduler_run_queue' );
    }
}
