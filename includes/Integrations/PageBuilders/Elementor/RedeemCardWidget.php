<?php
namespace YayLoyalty\Integrations\PageBuilders\Elementor;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Redeem card placement as an Elementor widget.
 *
 * @since 1.1.0
 */
final class RedeemCardWidget extends PlacementWidget {

    public function get_icon() {
        return 'eicon-cart';
    }

    protected function placement(): string {
        return PlacementWidgets::REDEEM_CARD;
    }
}
