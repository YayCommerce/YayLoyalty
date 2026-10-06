<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The site-level gate: is there a logged-in customer, is the program on,
 * are WooCommerce coupons enabled at all (the discount is a coupon, so a
 * store with coupons off cannot redeem). First failing rule wins, in that
 * order. Reads settings and the coupons option — the one gate that may.
 * Scaffold: stub.
 */
final class ProgramGate implements Gate {

    public const REASON_NO_CUSTOMER      = 'no_customer';
    public const REASON_PROGRAM_PAUSED   = 'program_paused';
    public const REASON_COUPONS_DISABLED = 'coupons_disabled';

    public function check( Reward $reward, Context $ctx ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): null user_id → REASON_NO_CUSTOMER; SettingsHelper general.enabled false → REASON_PROGRAM_PAUSED; wc_coupons_enabled() false → REASON_COUPONS_DISABLED; else null.
        throw new \RuntimeException( 'Not implemented' );
    }
}
