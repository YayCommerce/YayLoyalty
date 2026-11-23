<?php
namespace YayLoyalty\Integrations\PageBuilders\Elementor;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Points balance placement as an Elementor widget.
 *
 * @since 1.1.0
 */
final class PointsBalanceWidget extends PlacementWidget {

    public function get_icon() {
        return 'eicon-star-o';
    }

    protected function placement(): string {
        return PlacementWidgets::POINTS_BALANCE;
    }
}
