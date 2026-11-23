<?php
namespace YayTests\Unit\Integrations\PageBuilders;

use Brain\Monkey\Functions;
use YayLoyalty\Integrations\PageBuilders\PlacementWidgets;
use YayTests\Unit\TestCase;

/**
 * @group P-2
 */
final class PlacementWidgetsTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
	}

	public function test_output_is_the_shortcode_or_a_placeholder_only_in_preview() {
		Functions\expect( 'do_shortcode' )->with( '[yayloyalty_balance]' )->twice()->andReturn( '<div class="yayloyalty-placement">42</div>' );

		$this->assertSame( '<div class="yayloyalty-placement">42</div>', PlacementWidgets::output( PlacementWidgets::POINTS_BALANCE, false ) );
		$this->assertSame( '<div class="yayloyalty-placement">42</div>', PlacementWidgets::output( PlacementWidgets::POINTS_BALANCE, true ) );
	}

	public function test_empty_output_stays_empty_on_the_live_page_and_becomes_the_placeholder_in_preview() {
		Functions\expect( 'do_shortcode' )->with( '[yayloyalty_redeem]' )->twice()->andReturn( '' );

		$this->assertSame( '', PlacementWidgets::output( PlacementWidgets::REDEEM_CARD, false ) );

		$preview = PlacementWidgets::output( PlacementWidgets::REDEEM_CARD, true );

		$this->assertStringContainsString( 'yayloyalty-account__state', $preview );
		$this->assertStringContainsString( 'Redeem card', $preview );
	}

	public function test_an_unknown_key_prints_nothing() {
		Functions\expect( 'do_shortcode' )->never();

		$this->assertSame( '', PlacementWidgets::output( 'nope', true ) );
	}
}
