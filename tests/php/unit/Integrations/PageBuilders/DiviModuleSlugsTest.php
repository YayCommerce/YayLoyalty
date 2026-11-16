<?php
namespace YayTests\Unit\Integrations\PageBuilders;

use YayLoyalty\Engine\Storefront\Placements;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Integrations\PageBuilders\Divi\PointsBalanceModule;
use YayLoyalty\Integrations\PageBuilders\Divi\RedeemCardModule;
use YayLoyalty\Integrations\PageBuilders\Divi\WaysToEarnModule;
use YayLoyalty\Integrations\PageBuilders\Divi\WaysToRedeemModule;
use YayTests\Unit\TestCase;

if ( ! class_exists( '\ET_Builder_Module' ) ) {
	require_once __DIR__ . '/divi-builder-module-stub.php';
}

/**
 * @group P-2
 */
final class DiviModuleSlugsTest extends TestCase {

	public function test_no_divi_module_slug_is_a_loyalty_shortcode_tag() {
		$shortcodes = [
			Placements::BALANCE_SHORTCODE,
			Placements::WAYS_TO_EARN_SHORTCODE,
			Placements::WAYS_TO_REDEEM_SHORTCODE,
			RedeemCard::SHORTCODE,
		];

		foreach ( [ PointsBalanceModule::class, WaysToEarnModule::class, WaysToRedeemModule::class, RedeemCardModule::class ] as $module ) {
			$slug = ( new \ReflectionClass( $module ) )->getDefaultProperties()['slug'];

			$this->assertNotContains( $slug, $shortcodes, $module . ' would replace its own shortcode and render itself forever.' );
		}
	}
}
