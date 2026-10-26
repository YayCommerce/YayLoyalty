<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\RewardType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\PointsMath;
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

    /** The guest panel's signup step, by register route (LauncherPayload::register_route()). */
    public static function join_free( ?string $route ): string {
        return 'checkout' === $route ? __( 'Join for free at checkout', 'yayloyalty' ) : __( 'Join for free', 'yayloyalty' );
    }

    /** The guest panel's signup step value: "+100 welcome Points". */
    public static function welcome_points( int $points ): string {
        /* translators: 1: number of points with a plus sign, as "+100", 2: the store's points label, as "Points". */
        return sprintf( __( '%1$s welcome %2$s', 'yayloyalty' ), self::signed_points( $points ), SettingsHelper::points_label( $points ) );
    }

    /** The guest panel's purchase step: "Earn Points", with the store's label. */
    public static function earn_points(): string {
        /* translators: %s: the store's plural points label, as "Points". */
        return sprintf( __( 'Earn %s', 'yayloyalty' ), SettingsHelper::points_label_plural() );
    }

    /** "+270" / "−120": signed, with a real minus sign. */
    public static function signed_points( int $points ): string {
        return ( $points < 0 ? '−' : '+' ) . SettingsHelper::format_number( abs( $points ) );
    }

    /** A way to earn's fixed award with its label: "+50 Points". */
    public static function points_gain( int $points ): string {
        /* translators: 1: number of points with a plus sign, as "+50", 2: the store's points label, as "Points". */
        return sprintf( _x( '%1$s %2$s', 'points amount', 'yayloyalty' ), self::signed_points( $points ), SettingsHelper::points_label( $points ) );
    }

    /**
     * A rate like 1, 1.5 or 0.004, with every decimal the merchant entered. At most 10 places, which drops float noise.
     * The digits match the admin's pointsPerCurrencyLabel(), which owns them. The separators are the store's, as for money ("1,5" with a comma decimal separator).
     */
    public static function rate( float $rate ): string {
        $fixed = rtrim( sprintf( '%.10F', $rate ), '0' );

        return SettingsHelper::format_number( $rate, strlen( $fixed ) - strpos( $fixed, '.' ) - 1 );
    }

    /**
     * A `fixed_coupon` / `percent_coupon` reward's one line: what it takes off, or the minimum it needs.
     * The config's amounts are in the base currency, so each converts to the shopper's before money().
     */
    public static function coupon_reward_line( Reward $reward ): string {
        $config = $reward->get_config();

        if ( RewardType::PERCENT_COUPON === $reward->get_type() ) {
            if ( isset( $config['max_discount'] ) ) {
                /* translators: %s: money amount, as "$10". */
                return sprintf( __( 'Up to %s off your order', 'yayloyalty' ), self::money( self::from_base( (float) $config['max_discount'] ) ) );
            }

            /* translators: %s: a percentage number, as "15". */
            return sprintf( __( '%s%% off your order', 'yayloyalty' ), self::rate( (float) ( $config['percent'] ?? 0 ) ) );
        }

        if ( isset( $config['min_order'] ) ) {
            /* translators: %s: money amount, as "$25". */
            return sprintf( __( 'On orders over %s', 'yayloyalty' ), self::money( self::from_base( (float) $config['min_order'] ) ) );
        }

        /* translators: %s: money amount, as "$5". */
        return sprintf( __( '%s off your order', 'yayloyalty' ), self::money( self::from_base( (float) ( $config['amount'] ?? 0 ) ) ) );
    }

    /** "$98.50", or "$1" for a whole amount. Formats an amount already in the current currency; never converts. */
    public static function money( float $amount ): string {
        $args = floor( $amount ) === $amount ? [ 'decimals' => 0 ] : [];

        return Text::plain( wc_price( $amount, $args ) );
    }

    /**
     * The purchase earn rate: "1 Point / $1", with the label agreeing with the rate.
     * A campaign minimum is appended, since an order under it earns nothing.
     * A rate below 1 reads per point instead: "Earn 1 Point per 25,000 ₫ spent".
     */
    public static function earn_rate( float $points_per_unit, float $min_subtotal ): string {
        $per_point = self::is_per_point( $points_per_unit );
        $points    = $per_point ? 1.0 : $points_per_unit;

        $line = strtr(
            /* translators: keep every {…} placeholder as it is; each is filled in when the line is shown. */
            __( '{points} {points_label} / {amount}', 'yayloyalty' ),
            [
                '{points}'       => self::rate( $points ),
                '{points_label}' => self::rate_label( $points ),
                '{amount}'       => self::money( $per_point ? self::amount_per_point( $points_per_unit ) : 1.0 ),
            ]
        );

        if ( $min_subtotal <= 0 ) {
            return $line;
        }

        /* translators: 1: earn-rate sentence, 2: minimum order amount. */
        return sprintf( __( '%1$s on orders of %2$s or more', 'yayloyalty' ), $line, self::money( $min_subtotal ) );
    }

    /**
     * A line with {points} and a {points_label} that agrees with the count, as escaped HTML: the
     * figure in <strong>, with its label when they sit together, and with a leading "+" too ("+{points} {points_label}").
     * For the product-page line and the thank-you card.
     */
    public static function points_sentence_html( string $template, int $points ): string {
        $number = esc_html( self::points( $points ) );
        $label  = esc_html( SettingsHelper::points_label( $points ) );

        // strtr() tries the longest key first, so the widest phrase is bolded as one.
        return strtr(
            esc_html( $template ),
            [
                '+{points} {points_label}' => '<strong>+' . $number . ' ' . $label . '</strong>',
                '{points} {points_label}'  => '<strong>' . $number . ' ' . $label . '</strong>',
                '{points}'                 => '<strong>' . $number . '</strong>',
                '{points_label}'           => $label,
            ]
        );
    }

    /** The launcher button's accessible name and the panel's title. */
    public static function launcher_label(): string {
        return __( 'Rewards', 'yayloyalty' );
    }

    /** The points card's heading: "Your Points". */
    public static function balance_heading(): string {
        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( 'Your %s', 'yayloyalty' ), SettingsHelper::points_label_plural() );
    }

    /** The guest card's heading off a product page. */
    public static function signed_out_prompt(): string {
        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( 'Sign in to earn %s on this order', 'yayloyalty' ), SettingsHelper::points_label_plural() );
    }

    /** The empty points history, in the launcher and on the account tab. */
    public static function no_activity(): string {
        /* translators: %s: the store's points label, as "Points". */
        return sprintf( __( 'No %s activity yet.', 'yayloyalty' ), SettingsHelper::points_label_plural() );
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

    /** "100 points = $1", with the merchant's label. A rate below 1 reads per point instead: "1 Point = 25,000 ₫". */
    public static function redeem_rate( float $points_per_unit ): string {
        $per_point = self::is_per_point( $points_per_unit );
        $points    = $per_point ? 1.0 : $points_per_unit;
        $amount    = $per_point ? self::amount_per_point( $points_per_unit ) : 1.0;

        /* translators: 1: points per unit of currency, 2: the store's points label, 3: money amount. */
        return sprintf( __( '%1$s %2$s = %3$s', 'yayloyalty' ), self::rate( $points ), self::rate_label( $points ), self::money( $amount ) );
    }

    /**
     * A points rate converted into the shopper's currency, for the payload. At 1 or more it is rounded to 4 significant
     * digits; below 1 it stays exact, because earn_rate() and redeem_rate() print its inverse, which must land on the
     * currency's own amount (1 / 0.00003636… is 27,500, but 1 / 0.00003636 is not).
     */
    public static function converted_rate( float $rate ): float {
        return $rate >= 1 ? PointsMath::round_significant( $rate ) : $rate;
    }

    /** A base-currency amount in the shopper's currency, rounded once to its decimals so a whole amount prints whole. */
    private static function from_base( float $amount ): float {
        return round( CurrencyRate::from_base( $amount ), wc_get_price_decimals() );
    }

    /** A rate below 1 reads per point, converted or not: "0.00004 Points = 1 ₫" says less than "1 Point = 25,000 ₫". */
    private static function is_per_point( float $points_per_unit ): bool {
        return $points_per_unit > 0 && $points_per_unit < 1;
    }

    /** What one point is worth at $points_per_unit, rounded to the currency's decimals. */
    private static function amount_per_point( float $points_per_unit ): float {
        return round( 1 / $points_per_unit, wc_get_price_decimals() );
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
