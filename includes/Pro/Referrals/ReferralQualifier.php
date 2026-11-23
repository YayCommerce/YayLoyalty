<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Utils\CurrencyRate;

defined( 'ABSPATH' ) || exit;

/** Whether a friend's first order earns the advocate their points, and the subtotal its minimum is checked against. */
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

    /** The order's subtotal as the cart showed it when the minimum was checked: with line tax when the cart displays prices with tax. */
    public static function minimum_subtotal( \WC_Order $order ): float {
        $subtotal = (float) $order->get_subtotal();

        if ( 'incl' !== get_option( 'woocommerce_tax_display_cart' ) ) {
            return $subtotal;
        }

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $subtotal += (float) $item->get_subtotal_tax();
        }

        return $subtotal;
    }
}
