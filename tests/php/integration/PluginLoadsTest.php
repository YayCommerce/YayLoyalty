<?php
namespace YayTests\Integration;

use WP_UnitTestCase;

final class PluginLoadsTest extends WP_UnitTestCase {

	public function test_the_plugin_is_loaded() {
		$main = realpath( dirname( __DIR__, 3 ) . '/yayloyalty.php' );

		$this->assertContains( $main, array_map( 'realpath', get_included_files() ) );
	}

	public function test_woocommerce_saves_a_product() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Integration product' );
		$product->set_regular_price( '10' );
		$product->save();

		$this->assertSame( '10', wc_get_product( $product->get_id() )->get_regular_price() );
	}
}
