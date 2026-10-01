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

    /** A rate like 1 or 1.5, without trailing zeros. */
    public static function rate( float $rate ): string {
        $rate = round( $rate, 2 );

        if ( round( $rate ) === $rate ) {
            return number_format_i18n( $rate );
        }

        return number_format_i18n( $rate, round( $rate, 1 ) === $rate ? 1 : 2 );
    }

    /** "$98.50"; a whole amount drops its decimals ("$1"), as the design writes it. */
    public static function money( float $amount ): string {
        $args = floor( $amount ) === $amount ? [ 'decimals' => 0 ] : [];

        return Text::plain( wc_price( $amount, $args ) );
    }

    /**
     * The Design tab's `earn_rate_line` with its {points} and {amount} filled: "Earn 1 points per $1 spent".
     * The field is optional; left blank, the rate still needs saying, so the Design default stands in.
     */
    public static function earn_rate( string $template, float $points_per_unit ): string {
        if ( '' === trim( $template ) ) {
            $template = DesignSchema::get_default_design()['storefront_text']['earn_rate_line'];
        }

        return strtr(
            $template,
            [
                '{points}' => self::rate( $points_per_unit ),
                '{amount}' => self::money( 1.0 ),
            ]
        );
    }

    /** "100 points = $1". */
    public static function redeem_rate( float $points_per_unit ): string {
        /* translators: 1: points, 2: money amount. */
        return sprintf( __( '%1$s points = %2$s', 'yayloyalty' ), self::rate( $points_per_unit ), self::money( 1.0 ) );
    }
}
