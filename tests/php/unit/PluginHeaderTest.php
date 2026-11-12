<?php
namespace YayTests\Unit;

final class PluginHeaderTest extends TestCase {

	/** The source header names Pro; the Lite release strips " Pro" (scripts/run.sh). */
	public function test_main_file_declares_the_plugin() {
		$header = file_get_contents( dirname( __DIR__, 3 ) . '/yayloyalty.php' );

		$this->assertMatchesRegularExpression( '/Plugin Name:\s*YayLoyalty( Pro)? — Points, Rewards, Referral & VIP for WooCommerce/', $header );
	}
}
