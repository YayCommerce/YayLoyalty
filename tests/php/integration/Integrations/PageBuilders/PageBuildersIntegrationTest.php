<?php
namespace YayTests\Integration\Integrations\PageBuilders;

use WP_UnitTestCase;
use YayLoyalty\Integrations\PageBuilders\Bricks\BricksElements;
use YayLoyalty\Integrations\PageBuilders\Divi\DiviModules;
use YayLoyalty\Integrations\PageBuilders\Elementor\ElementorWidgets;

/**
 * The tests site has no Elementor, Bricks or Divi, as most stores don't.
 *
 * @group P-2
 */
final class PageBuildersIntegrationTest extends WP_UnitTestCase {

	public function test_each_builder_hook_is_added_at_boot() {
		$this->assertNotFalse( has_action( 'elementor/widgets/register', [ ElementorWidgets::class, 'register_widgets' ] ) );
		$this->assertNotFalse( has_action( 'elementor/elements/categories_registered', [ ElementorWidgets::class, 'register_category' ] ) );
		$this->assertSame( 11, has_action( 'init', [ BricksElements::class, 'register_elements' ] ) );
		$this->assertNotFalse( has_action( 'et_builder_ready', [ DiviModules::class, 'register_modules' ] ) );
	}

	public function test_a_site_without_a_builder_loads_no_builder_class() {
		BricksElements::register_elements();
		do_action( 'wp_enqueue_scripts' );

		foreach ( [ 'Elementor\PlacementWidget', 'Bricks\PlacementElement', 'Divi\PlacementModule' ] as $base ) {
			$this->assertFalse( class_exists( 'YayLoyalty\Integrations\PageBuilders\\' . $base, false ), $base . ' was loaded without its builder.' );
		}
	}
}
