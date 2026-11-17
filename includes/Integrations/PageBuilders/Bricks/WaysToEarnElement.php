<?php
namespace YayLoyalty\Integrations\PageBuilders\Bricks;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Ways to earn placement as a Bricks element.
 *
 * @since 1.1.0
 */
final class WaysToEarnElement extends PlacementElement {

    public const PLACEMENT = PlacementWidgets::WAYS_TO_EARN;

    public $name = 'yayloyalty-ways-to-earn';

    public $icon = 'ti-list';
}
