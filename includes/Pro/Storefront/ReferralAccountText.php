<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** The Referrals tab's wording: the offer, in the shopper's currency, and each invitation's status in the customer's words. */
final class ReferralAccountText {

    /**
     * "Give $10, get 1,000 Points".
     *
     * @param array<string, mixed> $settings ReferralSettings::get().
     */
    public static function heading( array $settings ): string {
        /* translators: 1: the friend's discount, e.g. "$10" or "10%", 2: points with their label */
        return sprintf( __( 'Give %1$s, get %2$s', 'yayloyalty' ), self::discount( $settings ), SettingsHelper::points_text( (int) $settings['advocate_points'] ) );
    }

    /**
     * The offer sentence, without the minimum or the hold when the settings have none.
     *
     * @param array<string, mixed> $settings ReferralSettings::get().
     */
    public static function sentence( array $settings ): string {
        $discount = self::discount( $settings );
        $minimum  = (float) $settings['min_order'];
        $points   = SettingsHelper::points_text( (int) $settings['advocate_points'] );
        $hold     = (int) $settings['hold_days'];

        $friend = $minimum > 0
            /* translators: 1: the friend's discount, 2: the minimum order */
            ? sprintf( __( 'Your friend gets %1$s off their first order over %2$s.', 'yayloyalty' ), $discount, LauncherCopy::money( CurrencyRate::from_base( $minimum ) ) )
            /* translators: %s: the friend's discount */
            : sprintf( __( 'Your friend gets %s off their first order.', 'yayloyalty' ), $discount );

        $you = $hold > 0
            /* translators: 1: points with their label, 2: days */
            ? sprintf( __( 'You get %1$s once their order completes and clears our %2$s-day hold.', 'yayloyalty' ), $points, number_format_i18n( $hold ) )
            /* translators: %s: points with their label */
            : sprintf( __( 'You get %s once their order completes.', 'yayloyalty' ), $points );

        return $friend . ' ' . $you;
    }

    /** `p•••@example.com`: the first character and the domain. */
    public static function mask_email( string $email ): string {
        $at = strpos( $email, '@' );

        return false === $at || $at < 1 ? $email : mb_substr( $email, 0, 1 ) . '•••' . substr( $email, $at );
    }

    /**
     * The invitation's status in the customer's words.
     *
     * @param array<string, mixed> $row A ReferralListRepository::paginate() item.
     */
    public static function status( array $row ): string {
        switch ( $row['status'] ) {
            case ReferralStatus::ON_HOLD:
                // A hold that has ended but isn't paid yet waits for the daily release.
                if ( (string) $row['hold_until'] < Dates::now() ) {
                    return __( 'On the way · arriving soon', 'yayloyalty' );
                }

                /* translators: %s: the date the points arrive */
                return sprintf( __( 'On the way · arrives %s', 'yayloyalty' ), Dates::to_site_date( (string) $row['hold_until'] ) );
            case ReferralStatus::REWARDED:
                return __( 'Completed', 'yayloyalty' );
            case ReferralStatus::REJECTED:
                return __( "Didn't qualify", 'yayloyalty' );
            case ReferralStatus::EXPIRED:
                $days = (int) round( ( Dates::to_timestamp( (string) $row['coupon_expires_at'] ) - Dates::to_timestamp( (string) $row['created_at'] ) ) / DAY_IN_SECONDS );

                /* translators: %s: days */
                return sprintf( __( 'Expired · no order within %s days', 'yayloyalty' ), number_format_i18n( $days ) );
            case ReferralStatus::REVERSED:
                return __( 'Order refunded', 'yayloyalty' );
            default:
                return __( 'Signed up · waiting on a first order', 'yayloyalty' );
        }
    }

    /**
     * "You earned": the points paid, the points on the way, or a dash.
     *
     * @param array<string, mixed> $row A ReferralListRepository::paginate() item.
     */
    public static function earned( array $row ): string {
        $points = (int) ( $row['terms']['advocate_points'] ?? 0 );

        if ( ReferralStatus::REWARDED === $row['status'] ) {
            return LauncherCopy::signed_points( $points );
        }

        return ReferralStatus::ON_HOLD === $row['status'] ? LauncherCopy::points( $points ) : '—';
    }

    /**
     * "$10" in the shopper's currency, or "10%".
     *
     * @param array<string, mixed> $settings ReferralSettings::get().
     */
    private static function discount( array $settings ): string {
        $amount = (float) $settings['discount_amount'];

        return ReferralSettings::DISCOUNT_PERCENT === $settings['discount_type']
            ? number_format_i18n( $amount, floor( $amount ) === $amount ? 0 : 2 ) . '%'
            : LauncherCopy::money( CurrencyRate::from_base( $amount ) );
    }
}
