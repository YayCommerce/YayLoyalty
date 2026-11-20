<?php
namespace YayLoyalty\Integrations\PageBuilders\Bricks;

use Bricks\Element;
use YayLoyalty\Engine\Storefront\Placements;
use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

/**
 * One placement as a Bricks element, with no controls of its own: the shortcodes take no attributes.
 *
 * @since 1.1.0
 */
abstract class PlacementElement extends Element {

    /** The PlacementWidgets key this element prints. */
    public const PLACEMENT = '';

    public $category = Placements::BLOCK_CATEGORY;

    public function get_label() {
        return PlacementWidgets::title( static::PLACEMENT );
    }

    public function get_keywords() {
        return [ 'yayloyalty', 'loyalty', 'points', 'rewards' ];
    }

    public function render() {
        $html = PlacementWidgets::output( static::PLACEMENT, bricks_is_builder() || bricks_is_builder_call() );

        if ( '' === $html ) {
            return;
        }

        echo "<div {$this->render_attributes( '_root' )}>" . wp_kses( $html, Kses::storefront() ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bricks escapes its own attributes.
    }
}
