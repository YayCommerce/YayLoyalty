<?php
namespace YayLoyalty\Integrations\PageBuilders;

use YayLoyalty\Integrations\PageBuilders\Bricks\BricksElements;
use YayLoyalty\Integrations\PageBuilders\Elementor\ElementorWidgets;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The loyalty placements as page builder elements. Only hooks are added here: each hook fires only when its builder
 * runs, and the element classes extend the builder's own, so they are first named inside those callbacks.
 *
 * @since 1.1.0
 */
final class PageBuildersIntegration {

    use SingletonTrait;

    protected function __construct() {
        ElementorWidgets::register();
        BricksElements::register();
    }
}
