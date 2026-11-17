<?php
namespace YayLoyalty\Integrations\PageBuilders\Elementor;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Ways to earn placement as an Elementor widget.
 *
 * @since 1.1.0
 */
final class WaysToEarnWidget extends PlacementWidget {

    public function get_icon() {
        return 'eicon-bullet-list';
    }

    protected function placement(): string {
        return PlacementWidgets::WAYS_TO_EARN;
    }
}
