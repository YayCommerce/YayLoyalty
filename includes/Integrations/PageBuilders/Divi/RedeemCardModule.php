<?php
namespace YayLoyalty\Integrations\PageBuilders\Divi;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * The Redeem card placement as a Divi 4 module.
 *
 * @since 1.1.0
 */
final class RedeemCardModule extends PlacementModule {

    public const PLACEMENT = PlacementWidgets::REDEEM_CARD;

    public $slug = 'yayloyalty_redeem_card';
}
