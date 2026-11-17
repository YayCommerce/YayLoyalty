<?php
namespace YayTests\Integration\Integrations\PageBuilders;

use WP_UnitTestCase;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;

/**
 * The elements' output against the real shortcodes, for a signed-in customer.
 *
 * @group P-2
 */
final class PlacementWidgetsTest extends WP_UnitTestCase {

	private const TAGS = [
		PlacementWidgets::POINTS_BALANCE => 'yayloyalty_balance',
		PlacementWidgets::WAYS_TO_EARN   => 'yayloyalty_ways_to_earn',
		PlacementWidgets::WAYS_TO_REDEEM => 'yayloyalty_ways_to_redeem',
		PlacementWidgets::REDEEM_CARD    => 'yayloyalty_redeem',
	];

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
	}

	public function test_live_output_is_the_registered_shortcode_output() {
		foreach ( self::TAGS as $key => $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), $tag . ' is not registered.' );
			$this->assertSame( trim( do_shortcode( '[' . $tag . ']' ) ), PlacementWidgets::output( $key, false ), $key );
		}

		$this->assertStringContainsString( 'yayloyalty-placement', PlacementWidgets::output( PlacementWidgets::POINTS_BALANCE, false ) );
	}

	public function test_a_paused_program_prints_nothing_live_and_the_placeholder_in_preview() {
		SettingsHelper::update_settings( [ 'enabled' => false ] );

		foreach ( array_keys( self::TAGS ) as $key ) {
			$this->assertSame( '', PlacementWidgets::output( $key, false ), $key );

			$preview = PlacementWidgets::output( $key, true );
			$this->assertStringContainsString( 'yayloyalty-account__state', $preview, $key );
			$this->assertStringContainsString( PlacementWidgets::title( $key ), $preview, $key );
		}
	}
}
