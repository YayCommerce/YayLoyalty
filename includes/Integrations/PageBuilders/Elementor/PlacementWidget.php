<?php
namespace YayLoyalty\Integrations\PageBuilders\Elementor;

use Elementor\Plugin;
use Elementor\Widget_Base;
use YayLoyalty\Engine\Storefront\Placements;
use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

/**
 * One placement as an Elementor widget, with no controls of its own: the shortcodes take no attributes.
 *
 * @since 1.1.0
 */
abstract class PlacementWidget extends Widget_Base {

    /** The PlacementWidgets key this widget prints. */
    abstract protected function placement(): string;

    public function get_name() {
        return 'yayloyalty-' . $this->placement();
    }

    public function get_title() {
        return PlacementWidgets::title( $this->placement() );
    }

    public function get_categories() {
        return [ Placements::BLOCK_CATEGORY ];
    }

    public function get_keywords() {
        return [ 'yayloyalty', 'loyalty', 'points', 'rewards' ];
    }

    protected function render() {
        $plugin     = Plugin::$instance;
        $is_preview = $plugin->editor->is_edit_mode() || $plugin->preview->is_preview_mode();

        echo wp_kses( PlacementWidgets::output( $this->placement(), $is_preview ), Kses::storefront() );
    }
}
