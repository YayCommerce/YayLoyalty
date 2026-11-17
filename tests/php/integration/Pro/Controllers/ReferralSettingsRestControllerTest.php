<?php
namespace YayTests\Integration\Pro\Controllers;

use WP_REST_Request;
use WP_UnitTestCase;
use YayLoyalty\Pro\Referrals\ReferralSettings;

/**
 * Referral settings decide what every new referral is promised: only campaign managers may change them.
 *
 * @group P-3
 */
final class ReferralSettingsRestControllerTest extends WP_UnitTestCase {

	private const ROUTE = '/yayloyalty/v1/referral/settings';

	public function set_up() {
		parent::set_up();
		delete_option( ReferralSettings::OPTION );
	}

	public function test_a_viewer_can_read_but_not_save() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view' ) );

		$read = rest_do_request( new WP_REST_Request( 'GET', self::ROUTE ) );
		$save = $this->post( [ 'hold_days' => 3 ] );

		$this->assertSame( 200, $read->get_status() );
		$this->assertSame( 14, $read->get_data()['hold_days'] );
		$this->assertSame( 403, $save->get_status() );
		$this->assertFalse( get_option( ReferralSettings::OPTION ) );
	}

	public function test_a_manager_saves_and_gets_the_sanitized_settings_back() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view', 'yayloyalty_manage_campaigns' ) );

		$response = $this->post(
			[
				'enabled'         => true,
				'min_order'       => '50',
				'advocate_points' => 1000,
				'bogus'           => 'x',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['enabled'] );
		$this->assertSame( '50', $response->get_data()['min_order'] );
		$this->assertArrayNotHasKey( 'bogus', get_option( ReferralSettings::OPTION ) );
	}

	public function test_an_out_of_range_value_is_refused_and_nothing_is_stored() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view', 'yayloyalty_manage_campaigns' ) );

		$response = $this->post(
			[
				'discount_type'   => 'percent',
				'discount_amount' => 150,
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( get_option( ReferralSettings::OPTION ) );
	}

	private function post( array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_do_request( $request );
	}

	private function user_with( string ...$caps ): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user    = get_userdata( $user_id );

		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}

		return $user_id;
	}
}
