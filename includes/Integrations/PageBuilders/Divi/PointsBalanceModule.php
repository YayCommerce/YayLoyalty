<?php
namespace YayLoyalty\Integrations\PageBuilders\Divi;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Points balance placement as a Divi 4 module.
 *
 * @since 1.1.0
 */
final class PointsBalanceModule extends PlacementModule {

    public const PLACEMENT = PlacementWidgets::POINTS_BALANCE;

    public $slug = 'yayloyalty_divi_points_balance';
}
