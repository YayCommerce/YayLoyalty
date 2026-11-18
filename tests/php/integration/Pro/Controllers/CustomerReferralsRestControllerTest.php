<?php
namespace YayTests\Integration\Pro\Controllers;

use WP_REST_Request;
use WP_UnitTestCase;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * The drawer's Referrals tab: every customer gets a link, once, and sees only their own referrals and totals.
 *
 * @group P-5
 */
final class CustomerReferralsRestControllerTest extends WP_UnitTestCase {

	/** @var int */
	private $advocate_id;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB
		wp_set_current_user( $this->viewer() );
		$this->advocate_id = self::factory()->user->create( [ 'role' => 'customer' ] );
	}

	public function test_a_customer_without_a_code_gets_one_and_keeps_it() {
		$first  = $this->get( $this->advocate_id )->get_data();
		$second = $this->get( $this->advocate_id )->get_data();

		$this->assertMatchesRegularExpression( '/[?&]yl_ref=[A-HJ-NP-Z2-9]{8}$/', $first['link'] );
		$this->assertSame( $first['link'], $second['link'] );
		$this->assertSame( 0, $first['counts']['referred'] );
	}

	public function test_the_totals_and_rows_are_the_advocates_own() {
		$repository = new ReferralRepository();
		$order      = wc_create_order();
		$order->set_total( '60.00' );
		$order->save();

		$rewarded = $repository->insert_pending( $this->advocate_id, $this->friend(), 'X', [ 'advocate_points' => 1000 ], Dates::offset( DAY_IN_SECONDS ) );
		$repository->hold( $rewarded, (int) $order->get_id(), Dates::offset( -60 ) );
		$repository->mark_rewarded( $rewarded );
		$repository->insert_pending( $this->advocate_id, $this->friend(), 'X', [ 'advocate_points' => 1000 ], Dates::offset( DAY_IN_SECONDS ) );
		$repository->insert_pending( self::factory()->user->create(), $this->friend(), 'Y', [ 'advocate_points' => 1000 ], Dates::offset( DAY_IN_SECONDS ) );

		$data = $this->get( $this->advocate_id )->get_data();

		$this->assertSame( 2, $data['totalItems'] );
		$this->assertSame(
			[
				'referred'         => 2,
				'rewarded'         => 1,
				'waiting'          => 1,
				'points_earned'    => 1000,
				'revenue_referred' => '60.00',
			],
			$data['counts']
		);
		$this->assertSame( [ $this->advocate_id ], array_unique( array_column( array_column( $data['data'], 'advocate' ), 'id' ) ) );
	}

	private function get( int $user_id ): \WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/yayloyalty/v1/customers/' . $user_id . '/referrals' ) );
	}

	private function friend(): int {
		return self::factory()->user->create( [ 'role' => 'customer' ] );
	}

	private function viewer(): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $user_id )->add_cap( 'yayloyalty_view' );

		return $user_id;
	}
}
