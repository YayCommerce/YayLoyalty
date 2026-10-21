<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\DesignSchema;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The launcher's numbers and sentences, formatted for the store: plain text, escaped by the template.
 *
 * Money goes through wc_price() so currency position, symbol and separators follow WooCommerce; the
 * markup it returns is stripped because these strings also land in attributes and aria labels.
 */
final class LauncherCopy {

    public static function points( int $points ): string {
        return SettingsHelper::format_number( $points );
    }

    /**
     * "Create an account", or "… at checkout" where shoppers can only register there.
     *
     * @param string|null $route LauncherPayload::register_route().
     */
    public static function create_account( ?string $route ): string {
        return 'checkout' === $route ? __( 'Create an account at checkout', 'yayloyalty' ) : __( 'Create an account', 'yayloyalty' );
    }

    /** "+270" / "−120": signed, with a real minus sign. */
    public static function signed_points( int $points ): string {
        return ( $points < 0 ? '−' : '+' ) . SettingsHelper::format_number( abs( $points ) );
    }

    /**
     * A rate like 1, 1.5 or 0.004, with every decimal the merchant entered. At most 10 places, which drops float noise.
     * The digits match the admin's pointsPerCurrencyLabel(), which owns them. The separators are the store's, as for money ("1,5" with a comma decimal separator).
     */
    public static function rate( float $rate ): string {
        $fixed = rtrim( sprintf( '%.10F', $rate ), '0' );

        return SettingsHelper::format_number( $rate, strlen( $fixed ) - strpos( $fixed, '.' ) - 1 );
    }

    /** A `fixed_coupon` / `percent_coupon` reward's one line: what it takes off, or the minimum it needs. */
    public static function coupon_reward_line( Reward $reward ): string {
        $config = $reward->get_config();

        if ( RewardType::PERCENT_COUPON === $reward->get_type() ) {
            if ( isset( $config['max_discount'] ) ) {
                /* translators: %s: money amount, as "$10". */
                return sprintf( __( 'Up to %s off your order', 'yayloyalty' ), self::money( (float) $config['max_discount'] ) );
            }

            /* translators: %s: a percentage number, as "15". */
            return sprintf( __( '%s%% off your order', 'yayloyalty' ), self::rate( (float) ( $config['percent'] ?? 0 ) ) );
        }

        if ( isset( $config['min_order'] ) ) {
            /* translators: %s: money amount, as "$25". */
            return sprintf( __( 'On orders over %s', 'yayloyalty' ), self::money( (float) $config['min_order'] ) );
        }

        /* translators: %s: money amount, as "$5". */
        return sprintf( __( '%s off your order', 'yayloyalty' ), self::money( (float) ( $config['amount'] ?? 0 ) ) );
    }

    /** "$98.50", or "$1" for a whole amount. */
    public static function money( float $amount ): string {
        $args = floor( $amount ) === $amount ? [ 'decimals' => 0 ] : [];

        return Text::plain( wc_price( $amount, $args ) );
    }

    /**
     * The Design tab's `earn_rate_line` with its {points}, {points_label} and {amount} filled: "1 Point / $1" by default.
     * The field is optional; left blank, the rate still needs saying, so the Design default stands in.
     * A campaign minimum is appended, since an order under it earns nothing.
     */
    public static function earn_rate( string $template, float $points_per_unit, float $min_subtotal ): string {
        if ( '' === trim( $template ) ) {
            $template = DesignSchema::get_default_design()['storefront_text']['earn_rate_line'];
        }

        $line = strtr(
            $template,
            [
                '{points}'       => self::rate( $points_per_unit ),
                '{points_label}' => self::rate_label( $points_per_unit ),
                '{amount}'       => self::money( 1.0 ),
            ]
        );

        if ( $min_subtotal <= 0 ) {
            return $line;
        }

        /* translators: 1: earn-rate sentence, 2: minimum order amount. */
        return sprintf( __( '%1$s on orders of %2$s or more', 'yayloyalty' ), $line, self::money( $min_subtotal ) );
    }

    /** A storefront_text line with {points} and a {points_label} that agrees with the count: "Earn 1 Point with this purchase". */
    public static function points_sentence( string $template, int $points ): string {
        return strtr(
            $template,
            [
                '{points}'       => self::points( $points ),
                '{points_label}' => SettingsHelper::points_label( $points ),
            ]
        );
    }

    /**
     * The Design tab's storefront_text with {points_label} filled with the merchant's plural label: "Your {points_label}" → "Your Stars".
     * earn_rate_line is left for earn_rate(), whose label agrees with the rate it prints.
     *
     * @param array<string, string> $text
     * @return array<string, string>
     */
    public static function storefront_text( array $text ): array {
        // 0 picks the plural label.
        $label = SettingsHelper::points_label( 0 );

        foreach ( $text as $key => $value ) {
            if ( 'earn_rate_line' !== $key ) {
                $text[ $key ] = str_replace( '{points_label}', $label, $value );
            }
        }

        return $text;
    }

    /** The empty points history, in the launcher and on the account tab. */
    public static function no_activity(): string {
        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( 'No %s activity yet.', 'yayloyalty' ), SettingsHelper::points_label( 0 ) );
    }

    /** The daily earning limit under the ways to earn: '' with no limit, "You can earn 40 Points more today." below it, a reached line at 0. */
    public static function daily_limit_line( ?int $left_today ): string {
        if ( null === $left_today ) {
            return '';
        }

        if ( 0 === $left_today ) {
            /* translators: %s: the store's plural points label, e.g. "Points". */
            return sprintf( __( 'You\'ve reached today\'s earning limit. Earn more %s tomorrow.', 'yayloyalty' ), SettingsHelper::points_label_plural() );
        }

        /* translators: %s: points with label, e.g. "40 Points". */
        return sprintf( __( 'You can earn %s more today.', 'yayloyalty' ), SettingsHelper::points_text( $left_today ) );
    }

    /** "100 points = $1", with the merchant's label. */
    public static function redeem_rate( float $points_per_unit ): string {
        /* translators: 1: points per unit of currency, 2: the store's points label, 3: money amount. */
        return sprintf( __( '%1$s %2$s = %3$s', 'yayloyalty' ), self::rate( $points_per_unit ), self::rate_label( $points_per_unit ), self::money( 1.0 ) );
    }

    /**
     * The limits under the redeem rate, as Clamp applies them: "From 100 Points, up to the full order value."
     * Both caps named when both are set, since the lower one binds.
     *
     * @param array{min_points: int, max_points: ?int, max_percent: ?float} $redeem LauncherPayload's `redeem`.
     */
    public static function redeem_limits( array $redeem ): string {
        $max_points  = $redeem['max_points'];
        $max_percent = null === $redeem['max_percent'] ? null : self::rate( $redeem['max_percent'] );

        if ( null !== $max_points && null !== $max_percent ) {
            /* translators: 1: points amount with the store's label, as "5,000 Points", 2: a percentage number, as "50". */
            $cap = sprintf( __( '%1$s or %2$s%% of the order subtotal', 'yayloyalty' ), SettingsHelper::points_text( $max_points ), $max_percent );
        } elseif ( null !== $max_points ) {
            /* translators: %s: points amount with the store's label, as "5,000 Points". */
            $cap = sprintf( __( '%s per order', 'yayloyalty' ), SettingsHelper::points_text( $max_points ) );
        } elseif ( null !== $max_percent ) {
            /* translators: %s: a percentage number, as "50". */
            $cap = sprintf( __( '%s%% of the order subtotal', 'yayloyalty' ), $max_percent );
        } else {
            $cap = __( 'the full order value', 'yayloyalty' );
        }

        if ( $redeem['min_points'] <= 0 ) {
            /* translators: %s: the most one order can take, as "the full order value" or "5,000 Points per order". */
            return sprintf( __( 'Up to %s.', 'yayloyalty' ), $cap );
        }

        /* translators: 1: points amount with the store's label, as "100 Points", 2: the most one order can take, as "the full order value". */
        return sprintf( __( 'From %1$s, up to %2$s.', 'yayloyalty' ), SettingsHelper::points_text( $redeem['min_points'] ), $cap );
    }

    /** The merchant's label for a rate: singular only when rate() prints it as 1, so number and label agree. */
    private static function rate_label( float $rate ): string {
        return SettingsHelper::points_label( '1' === self::rate( $rate ) ? 1 : 0 );
    }
}
