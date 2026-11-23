<?php
namespace YayLoyalty\Integrations\PageBuilders\Elementor;

use YayLoyalty\Engine\Storefront\Placements;
use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The four placements as Elementor widgets, in a "YayLoyalty" panel category.
 *
 * @since 1.1.0
 */
final class ElementorWidgets {

    public static function register(): void {
        add_action( 'elementor/elements/categories_registered', [ self::class, 'register_category' ] );
        add_action( 'elementor/widgets/register', [ self::class, 'register_widgets' ] );
        add_action( 'elementor/preview/enqueue_styles', [ PlacementWidgets::class, 'enqueue_preview_styles' ] );
    }

    /** @param \Elementor\Elements_Manager $elements_manager */
    public static function register_category( $elements_manager ): void {
        $elements_manager->add_category(
            Placements::BLOCK_CATEGORY,
            [
                'title' => 'YayLoyalty',
                'icon'  => 'eicon-star-o',
            ]
        );
    }

    /** @param \Elementor\Widgets_Manager $widgets_manager */
    public static function register_widgets( $widgets_manager ): void {
        $widgets_manager->register( new PointsBalanceWidget() );
        $widgets_manager->register( new WaysToEarnWidget() );
        $widgets_manager->register( new WaysToRedeemWidget() );
        $widgets_manager->register( new RedeemCardWidget() );
    }
}
