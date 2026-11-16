<?php
namespace YayLoyalty\Integrations\PageBuilders\Divi;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Ways to earn placement as a Divi 4 module.
 *
 * @since 1.1.0
 */
final class WaysToEarnModule extends PlacementModule {

    public const PLACEMENT = PlacementWidgets::WAYS_TO_EARN;

    public $slug = 'yayloyalty_ways_to_earn';
}
