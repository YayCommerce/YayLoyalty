<?php
namespace YayLoyalty\Integrations\PageBuilders\Bricks;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Redeem card placement as a Bricks element.
 *
 * @since 1.1.0
 */
final class RedeemCardElement extends PlacementElement {

    public const PLACEMENT = PlacementWidgets::REDEEM_CARD;

    public $name = 'yayloyalty-redeem-card';

    public $icon = 'ti-shopping-cart';
}
