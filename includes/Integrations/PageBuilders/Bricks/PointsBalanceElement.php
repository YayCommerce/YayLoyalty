<?php
namespace YayLoyalty\Integrations\PageBuilders\Bricks;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Points balance placement as a Bricks element.
 *
 * @since 1.1.0
 */
final class PointsBalanceElement extends PlacementElement {

    public const PLACEMENT = PlacementWidgets::POINTS_BALANCE;

    public $name = 'yayloyalty-points-balance';

    public $icon = 'ti-star';
}
