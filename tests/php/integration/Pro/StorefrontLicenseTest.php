<?php
namespace YayTests\Integration\Pro;

use WP_UnitTestCase;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Storefront\AccountMyPoints;
use YayLoyalty\Engine\Storefront\Placements;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Storefront\AccountReferrals;

/**
 * Pro with no activated license shows customers nothing of YayLoyalty; an activated key brings it back.
 *
 * @group P-6
 */
final class StorefrontLicenseTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );
		SettingsHelper::update_settings( [ 'enabled' => true ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
	}

	public function test_with_an_activated_key_the_storefront_shows() {
		$this->assertTrue( ProgramGate::storefront_enabled() );
		$this->assertArrayHasKey( AccountMyPoints::ENDPOINT, AccountMyPoints::add_menu_item( [] ) );
		$this->assertTrue( AccountReferrals::is_available( get_current_user_id() ) );
		$this->assertNotSame( '', Placements::balance() );
	}

	public function test_without_a_key_the_storefront_shows_nothing() {
		delete_option( 'yayloyalty_license_key' );

		$this->assert_hidden();
	}

	public function test_a_revoked_key_hides_the_storefront() {
		update_option( 'yayloyalty_license_info', [ 'license' => 'disabled' ] );

		$this->assert_hidden();
	}

	private function assert_hidden(): void {
		$this->assertFalse( ProgramGate::storefront_enabled() );
		$this->assertTrue( ProgramGate::program_enabled() );
		$this->assertSame( [], AccountMyPoints::add_menu_item( [] ) );
		$this->assertFalse( AccountReferrals::is_available( get_current_user_id() ) );
		$this->assertSame( '', Placements::balance() );
	}
}
