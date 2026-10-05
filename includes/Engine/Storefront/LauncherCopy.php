<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Helpers\DesignSchema;
use YayLoyalty\Helpers\SettingsHelper;
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
        return number_format_i18n( $points );
    }

    /** "+270" / "−120": signed, with a real minus sign. */
    public static function signed_points( int $points ): string {
        return ( $points < 0 ? '−' : '+' ) . number_format_i18n( abs( $points ) );
    }

    /**
     * A rate like 1, 1.5 or 0.004, with every decimal the merchant entered. The admin's
     * pointsPerCurrencyLabel() owns the digits (it prints String(rate)), and the two must show the same ones;
     * this copy also localises the separators for shoppers ("1,5" in German). Ten places at most drops float noise.
     */
    public static function rate( float $rate ): string {
        $fixed = rtrim( sprintf( '%.10F', $rate ), '0' );

        return number_format_i18n( $rate, strlen( $fixed ) - strpos( $fixed, '.' ) - 1 );
    }

    /** "$98.50"; a whole amount drops its decimals ("$1"), as the design writes it. */
    public static function money( float $amount ): string {
        $args = floor( $amount ) === $amount ? [ 'decimals' => 0 ] : [];

        return Text::plain( wc_price( $amount, $args ) );
    }

    /**
     * The Design tab's `earn_rate_line` with its {points}, {points_label} and {amount} filled: "Earn 1 Point per $1 spent".
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

    /** "100 points = $1", with the merchant's label. */
    public static function redeem_rate( float $points_per_unit ): string {
        /* translators: 1: points per unit of currency, 2: the store's points label, 3: money amount. */
        return sprintf( __( '%1$s %2$s = %3$s', 'yayloyalty' ), self::rate( $points_per_unit ), self::rate_label( $points_per_unit ), self::money( 1.0 ) );
    }

    /** The merchant's label for a rate: singular only when rate() prints it as 1, so number and label agree. */
    private static function rate_label( float $rate ): string {
        return SettingsHelper::points_label( '1' === self::rate( $rate ) ? 1 : 0 );
    }
}
