<?php
namespace YayLoyalty\Integrations\PageBuilders\Divi;

use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

defined( 'ABSPATH' ) || exit;

/**
 * One placement as a Divi 4 module, with no content fields: the shortcodes take no attributes.
 * Divi 4 has no module groups, so the name carries "YayLoyalty" to keep the four together in the module list.
 *
 * @since 1.1.0
 */
abstract class PlacementModule extends \ET_Builder_Module {

    /** The PlacementWidgets key this module prints. */
    public const PLACEMENT = '';

    public $vb_support = 'partial';

    public function init() {
        /* translators: %s: the placement's name, as "Points balance". */
        $this->name = sprintf( __( 'YayLoyalty %s', 'yayloyalty' ), PlacementWidgets::title( static::PLACEMENT ) );
    }

    public function get_fields() {
        return [];
    }

    /**
     * @param array  $attrs       Module attributes.
     * @param string $content     Module content.
     * @param string $render_slug The module's slug.
     */
    public function render( $attrs, $content, $render_slug ) {
        $html = PlacementWidgets::output( static::PLACEMENT, DiviModules::is_preview() );

        // Divi adds this wrapper (order class, CSS ID and class, spacing) itself only with full Visual Builder support.
        return '' === $html ? '' : $this->_render_module_wrapper( $html, $render_slug );
    }
}
