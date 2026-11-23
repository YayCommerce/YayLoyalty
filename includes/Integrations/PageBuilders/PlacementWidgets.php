<?php
namespace YayLoyalty\Integrations\PageBuilders;

use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Engine\Storefront\OutputCapture;
use YayLoyalty\Engine\Storefront\Placements;
use YayLoyalty\Engine\Storefront\RedeemCard;

defined( 'ABSPATH' ) || exit;

/**
 * The four placements every page builder element prints, once for Elementor, Bricks and Divi.
 *
 * @since 1.1.0
 */
final class PlacementWidgets {

    public const POINTS_BALANCE = 'points-balance';
    public const WAYS_TO_EARN   = 'ways-to-earn';
    public const WAYS_TO_REDEEM = 'ways-to-redeem';
    public const REDEEM_CARD    = 'redeem-card';

    private const SHORTCODES = [
        self::POINTS_BALANCE => Placements::BALANCE_SHORTCODE,
        self::WAYS_TO_EARN   => Placements::WAYS_TO_EARN_SHORTCODE,
        self::WAYS_TO_REDEEM => Placements::WAYS_TO_REDEEM_SHORTCODE,
        self::REDEEM_CARD    => RedeemCard::SHORTCODE,
    ];

    /**
     * The element's name in the builder's panel.
     *
     * @param string $key One of the class constants.
     */
    public static function title( string $key ): string {
        switch ( $key ) {
            case self::POINTS_BALANCE:
                return __( 'Points balance', 'yayloyalty' );
            case self::WAYS_TO_EARN:
                return __( 'Ways to earn', 'yayloyalty' );
            case self::WAYS_TO_REDEEM:
                return __( 'Ways to redeem', 'yayloyalty' );
            default:
                return __( 'Redeem card', 'yayloyalty' );
        }
    }

    /**
     * The placement's HTML: its shortcode's output, or, when that is empty in a builder preview, the placeholder.
     *
     * @param string $key        One of the class constants.
     * @param bool   $is_preview Whether the builder is drawing its editing canvas.
     */
    public static function output( string $key, bool $is_preview ): string {
        if ( ! isset( self::SHORTCODES[ $key ] ) ) {
            return '';
        }

        $html = trim( (string) do_shortcode( '[' . self::SHORTCODES[ $key ] . ']' ) );

        if ( '' !== $html || ! $is_preview ) {
            return $html;
        }

        return self::placeholder( $key );
    }

    /** Both stylesheets the elements use, enqueued up front in a builder's frame, which re-renders an element after the page has loaded. */
    public static function enqueue_preview_styles(): void {
        wp_enqueue_style( ScriptName::ACCOUNT_STYLE );
        RedeemCard::enqueue_assets();
    }

    private static function placeholder( string $key ): string {
        $title = self::title( $key );
        $text  = self::hint( $key );

        $html = OutputCapture::capture(
            static function () use ( $title, $text ): void {
                require dirname( __DIR__, 2 ) . '/Engine/Storefront/templates/account-rewards-state.php';
            },
            'PlacementWidgets'
        );

        wp_enqueue_style( ScriptName::ACCOUNT_STYLE );

        return trim( (string) $html );
    }

    private static function hint( string $key ): string {
        switch ( $key ) {
            case self::POINTS_BALANCE:
                return __( 'Shows the signed-in customer’s points balance, or a sign-in link for guests.', 'yayloyalty' );
            case self::WAYS_TO_EARN:
                return __( 'Lists the ways the signed-in customer can earn points.', 'yayloyalty' );
            case self::WAYS_TO_REDEEM:
                return __( 'Lists the ways the signed-in customer can spend points.', 'yayloyalty' );
            default:
                return __( 'Lets the customer spend points on their cart. Shows when the cart has items.', 'yayloyalty' );
        }
    }
}
