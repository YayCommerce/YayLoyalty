<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Earning\ProgramGate as EarnProgramGate;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;

defined( 'ABSPATH' ) || exit;

/**
 * The site-level gate, checked in order: a signed-in customer, the program
 * on, the customer not opted out, their role not excluded, and WooCommerce
 * coupons enabled (the discount is a coupon, so a store with coupons off
 * cannot redeem). The first failing rule wins. Reads settings, user meta
 * and the coupons option — the one gate that may.
 */
final class ProgramGate implements Gate {

    /** The four reasons the earning gate also gives, under its strings. */
    public const REASON_NO_CUSTOMER      = EarnProgramGate::REASON_NO_CUSTOMER;
    public const REASON_PROGRAM_PAUSED   = EarnProgramGate::REASON_PROGRAM_PAUSED;
    public const REASON_OPTED_OUT        = EarnProgramGate::REASON_OPTED_OUT;
    public const REASON_EXCLUDED_ROLE    = EarnProgramGate::REASON_EXCLUDED_ROLE;
    public const REASON_COUPONS_DISABLED = 'coupons_disabled';

    public function check( Reward $reward, Context $ctx ): ?string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the rules apply to every reward.
        $user_id = $ctx->get_user_id();

        if ( null === $user_id ) {
            return self::REASON_NO_CUSTOMER;
        }

        if ( ! EarnProgramGate::program_enabled() ) {
            return self::REASON_PROGRAM_PAUSED;
        }

        if ( get_user_meta( $user_id, EarnProgramGate::META_OPTED_OUT, true ) ) {
            return self::REASON_OPTED_OUT;
        }

        $user  = get_userdata( $user_id );
        $roles = $user instanceof \WP_User ? (array) $user->roles : [];

        if ( array_intersect( $roles, (array) SettingsHelper::get_settings()['general']['excluded_roles'] ) ) {
            return self::REASON_EXCLUDED_ROLE;
        }

        if ( ! wc_coupons_enabled() ) {
            return self::REASON_COUPONS_DISABLED;
        }

        return null;
    }
}
