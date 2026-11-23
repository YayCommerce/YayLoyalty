<?php
namespace YayLoyalty\Integrations\PageBuilders\Elementor;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Ways to redeem placement as an Elementor widget.
 *
 * @since 1.1.0
 */
final class WaysToRedeemWidget extends PlacementWidget {

    public function get_icon() {
        return 'eicon-price-list';
    }

    protected function placement(): string {
        return PlacementWidgets::WAYS_TO_REDEEM;
    }
}
