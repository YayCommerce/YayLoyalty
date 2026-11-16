<?php
namespace YayLoyalty\Integrations\PageBuilders\Bricks;

use YayLoyalty\Engine\Storefront\Placements;
use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The four placements as Bricks elements, in a "YayLoyalty" panel category.
 *
 * @since 1.1.0
 */
final class BricksElements {

    private const ELEMENTS = [
        PointsBalanceElement::class,
        WaysToEarnElement::class,
        WaysToRedeemElement::class,
        RedeemCardElement::class,
    ];

    public static function register(): void {
        // Bricks loads its element base class on init at 10.
        add_action( 'init', [ self::class, 'register_elements' ], 11 );
        add_filter( 'bricks/builder/i18n', [ self::class, 'category_label' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue_builder_styles' ] );
    }

    public static function register_elements(): void {
        if ( ! class_exists( '\Bricks\Elements' ) ) {
            return;
        }

        foreach ( self::ELEMENTS as $class ) {
            $short = substr( $class, strrpos( $class, '\\' ) + 1 );

            // Without the class, Bricks takes the last declared class, which can be the abstract base.
            \Bricks\Elements::register_element( __DIR__ . '/' . $short . '.php', '', $class );
        }
    }

    /**
     * @param array<string, string> $i18n
     * @return array<string, string>
     */
    public static function category_label( $i18n ) {
        $i18n[ Placements::BLOCK_CATEGORY ] = 'YayLoyalty';

        return $i18n;
    }

    public static function enqueue_builder_styles(): void {
        if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
            PlacementWidgets::enqueue_preview_styles();
        }
    }
}
