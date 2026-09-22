<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;

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

    /** Attaches user_register (and woocommerce_created_customer, same user, same key). */
    public function register(): void {
        // TODO(F01-4): attach on_registered to user_register.
    }

    public function on_registered( int $user_id ): void {
        // TODO(F01-4): ContextFactory::from_user( user_id, user_registered ); service->award( SIGNUP, ctx ).
    }
}
