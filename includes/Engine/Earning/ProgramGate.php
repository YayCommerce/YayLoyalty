<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Engine\Points\Context;

defined( 'ABSPATH' ) || exit;

/**
 * The Lite gate: is the program on, is there a customer, did they opt
 * out, is their role excluded. First failing rule wins, in that order.
 * Reads settings and user meta — the one place on the earning path that
 * may, because it is neither the calculation nor the write.
 */
final class ProgramGate implements Gate {

    /** User meta a customer sets to stop earning; any truthy value opts out. */
    public const META_OPTED_OUT = '_yayloyalty_opted_out';

    public const REASON_PROGRAM_PAUSED = 'program_paused';
    public const REASON_NO_CUSTOMER    = 'no_customer';
    public const REASON_OPTED_OUT      = 'opted_out';
    public const REASON_EXCLUDED_ROLE  = 'excluded_role';

    public function check( string $trigger, Context $ctx ): ?string {
        // TODO(F01-4): SettingsHelper general.enabled false → REASON_PROGRAM_PAUSED; null user_id → REASON_NO_CUSTOMER; META_OPTED_OUT truthy → REASON_OPTED_OUT; any of $ctx->get_user_roles() in general.excluded_roles → REASON_EXCLUDED_ROLE; else null.
        throw new \RuntimeException( 'Not implemented' );
    }
}
