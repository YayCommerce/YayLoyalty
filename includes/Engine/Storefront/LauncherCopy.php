<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Helpers\DesignSchema;
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
     * A rate like 1, 1.5 or 0.004, with every decimal the merchant entered: the admin's
     * pointsPerCurrencyLabel() prints String(rate), and the two must agree. Ten places at most drops float noise.
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
     * The Design tab's `earn_rate_line` with its {points} and {amount} filled: "Earn 1 points per $1 spent".
     * The field is optional; left blank, the rate still needs saying, so the Design default stands in.
     * A campaign minimum is appended, since an order under it earns nothing.
     */
    public static function earn_rate( string $template, float $points_per_unit, float $min_subtotal = 0.0 ): string {
        if ( '' === trim( $template ) ) {
            $template = DesignSchema::get_default_design()['storefront_text']['earn_rate_line'];
        }

        $line = strtr(
            $template,
            [
                '{points}' => self::rate( $points_per_unit ),
                '{amount}' => self::money( 1.0 ),
            ]
        );

        if ( $min_subtotal <= 0 ) {
            return $line;
        }

        /* translators: 1: earn-rate sentence, 2: minimum order amount. */
        return sprintf( __( '%1$s on orders of %2$s or more', 'yayloyalty' ), $line, self::money( $min_subtotal ) );
    }

    /** "100 points = $1". */
    public static function redeem_rate( float $points_per_unit ): string {
        /* translators: 1: points, 2: money amount. */
        return sprintf( __( '%1$s points = %2$s', 'yayloyalty' ), self::rate( $points_per_unit ), self::money( 1.0 ) );
    }
}
