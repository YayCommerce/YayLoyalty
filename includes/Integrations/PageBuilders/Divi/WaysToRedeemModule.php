<?php
namespace YayLoyalty\Integrations\PageBuilders\Divi;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Ways to redeem placement as a Divi 4 module.
 *
 * @since 1.1.0
 */
final class WaysToRedeemModule extends PlacementModule {

    public const PLACEMENT = PlacementWidgets::WAYS_TO_REDEEM;

    public $slug = 'yayloyalty_divi_ways_to_redeem';
}
