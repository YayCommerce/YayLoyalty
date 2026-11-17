<?php
namespace YayLoyalty\Integrations\PageBuilders\Bricks;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Ways to redeem placement as a Bricks element.
 *
 * @since 1.1.0
 */
final class WaysToRedeemElement extends PlacementElement {

    public const PLACEMENT = PlacementWidgets::WAYS_TO_REDEEM;

    public $name = 'yayloyalty-ways-to-redeem';

    public $icon = 'ti-gift';
}
