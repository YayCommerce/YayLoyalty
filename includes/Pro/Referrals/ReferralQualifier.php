<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Utils\CurrencyRate;

defined( 'ABSPATH' ) || exit;

/** Whether a friend's first order earns the advocate their points, from facts read elsewhere. */
final class ReferralQualifier {

    /**
     * The reason the order doesn't qualify, or null when it does.
     *
     * @param array<string, mixed> $terms         The referral's terms snapshot.
     * @param float                $subtotal_base The order's subtotal as WooCommerce's minimum check reads it, in base currency.
     * @param string[]             $coupon_codes  The order's coupon codes.
     * @param string               $own_code      This referral's coupon code.
     */
    public static function check( array $terms, float $subtotal_base, array $coupon_codes, string $own_code, string $billing_email, string $advocate_email ): ?string {
        $decimals = CurrencyRate::base_decimals();

        if ( round( $subtotal_base, $decimals ) < round( (float) ( $terms['min_order'] ?? 0 ), $decimals ) ) {
            return ReferralRejectReason::NOT_QUALIFYING;
        }

        if ( ! empty( $terms['exclude_other_coupons'] ) ) {
            $others = array_diff( array_map( 'strval', $coupon_codes ), [ $own_code ], Context::loyalty_codes( $coupon_codes ) );

            if ( [] !== $others ) {
                return ReferralRejectReason::NOT_QUALIFYING;
            }
        }

        if ( '' !== $advocate_email && 0 === strcasecmp( trim( $billing_email ), trim( $advocate_email ) ) ) {
            return ReferralRejectReason::SELF_REFERRAL;
        }

        return null;
    }
}
