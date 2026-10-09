<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Lite's gate, checked in order: program on, a customer, not opted out, role not excluded.
 * The first failing rule wins. The one place on the earning path that reads settings and user meta.
 */
final class ProgramGate implements Gate {

    /** User meta a customer sets to stop earning; any truthy value opts out. */
    public const META_OPTED_OUT = '_yayloyalty_opted_out';

    public const REASON_PROGRAM_PAUSED = 'program_paused';
    public const REASON_NO_CUSTOMER    = 'no_customer';
    public const REASON_OPTED_OUT      = 'opted_out';
    public const REASON_EXCLUDED_ROLE  = 'excluded_role';

    /** Whether the program is on; the storefront launcher asks this for guests, who have no Context to check. */
    public static function program_enabled(): bool {
        return (bool) SettingsHelper::get_settings()['enabled'];
    }

    public function check( string $trigger, Context $ctx ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the rules apply to every trigger.
        if ( ! self::program_enabled() ) {
            return self::REASON_PROGRAM_PAUSED;
        }

        $user_id = $ctx->get_user_id();

        if ( null === $user_id ) {
            return self::REASON_NO_CUSTOMER;
        }

        return self::member_denial( $user_id, $ctx->get_user_roles() );
    }

    /**
     * Why this customer is outside the program, opted out before excluded role, or null. Shared with
     * Engine\Redemption\ProgramGate, so who may earn and who may redeem cannot drift apart.
     *
     * @param string[] $roles The customer's roles.
     */
    public static function member_denial( int $user_id, array $roles ): ?string {
        if ( get_user_meta( $user_id, self::META_OPTED_OUT, true ) ) {
            return self::REASON_OPTED_OUT;
        }

        if ( array_intersect( $roles, SettingsHelper::get_settings()['excluded_roles'] ) ) {
            return self::REASON_EXCLUDED_ROLE;
        }

        return null;
    }
}
