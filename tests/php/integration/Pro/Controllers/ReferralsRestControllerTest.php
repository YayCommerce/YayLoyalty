<?php
namespace YayTests\Integration\Pro\Controllers;

use WP_REST_Request;
use WP_UnitTestCase;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * The admin's referral list: filtered by status, searched by either person's email, counted per status, readable by viewers.
 *
 * @group P-5
 */
final class ReferralsRestControllerTest extends WP_UnitTestCase {

	private const ROUTE = '/yayloyalty/v1/referrals';

	/** @var int */
	protected $advocate_id;

	/** @var int[] Referral ids: one pending, one held. */
	protected $referrals = [];

	public function set_up() {
		parent::set_up();
		global $wpdb;

		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB

		$this->advocate_id = self::factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'advocate@example.test',
			]
		);
		$repository        = new ReferralRepository();

		foreach ( [ 'pending-friend@example.test', 'held-friend@example.test' ] as $email ) {
			$friend            = self::factory()->user->create(
				[
					'role'       => 'customer',
					'user_email' => $email,
				]
			);
			$this->referrals[] = $repository->insert_pending( $this->advocate_id, $friend, 'TESTREF1', [ 'advocate_points' => 1000 ], Dates::offset( 30 * DAY_IN_SECONDS ) );
		}

		$repository->hold( $this->referrals[1], 0, Dates::offset( DAY_IN_SECONDS ) );
	}

	public function test_a_viewer_lists_by_status_with_the_counts_per_status() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view' ) );

		$response = $this->get( [ 'status' => 'on_hold' ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['totalItems'] );
		$this->assertSame( $this->referrals[1], $data['data'][0]['id'] );
		$this->assertSame( 'advocate@example.test', $data['data'][0]['advocate']['email'] );
		$this->assertSame( 1000, $data['data'][0]['advocate_points'] );
		$this->assertEquals(
			[
				'pending' => 1,
				'on_hold' => 1,
			],
			$data['counts']
		);
	}

	public function test_search_finds_the_friend_or_the_advocate_by_email() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view' ) );

		$this->assertSame( [ $this->referrals[0] ], array_column( $this->get( [ 'search' => 'pending-friend@' ] )->get_data()['data'], 'id' ) );
		$this->assertSame( 2, $this->get( [ 'search' => 'advocate@example' ] )->get_data()['totalItems'] );
	}

	public function test_approve_pays_once_and_a_second_approve_is_a_conflict() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view', 'yayloyalty_manage_campaigns' ) );
		$this->give_held_referral_a_completed_order();

		$first  = $this->post( $this->referrals[1], 'approve' );
		$second = $this->post( $this->referrals[1], 'approve' );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'rewarded', $first->get_data()['status'] );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'yayloyalty_referral_changed', $second->get_data()['code'] );
		$this->assertCount( 1, $this->referral_rows() );
	}

	public function test_approving_a_pending_referral_is_a_conflict() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view', 'yayloyalty_manage_campaigns' ) );

		$this->assertSame( 409, $this->post( $this->referrals[0], 'approve' )->get_status() );
	}

	public function test_reject_moves_only_a_held_referral_with_an_allowed_reason() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view', 'yayloyalty_manage_campaigns' ) );

		$this->assertSame( 400, $this->post( $this->referrals[1], 'reject', [ 'reason' => 'refunded_during_hold' ] )->get_status() );
		$this->assertSame( 409, $this->post( $this->referrals[0], 'reject', [ 'reason' => 'not_genuine' ] )->get_status() );

		$response = $this->post( $this->referrals[1], 'reject', [ 'reason' => 'not_genuine' ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'not_genuine', ( new ReferralRepository() )->find( $this->referrals[1] )->to_array()['reject_reason'] );
		$this->assertSame( [], $this->referral_rows() );
	}

	public function test_a_viewer_cannot_approve_or_reject() {
		wp_set_current_user( $this->user_with( 'yayloyalty_view' ) );

		$this->assertSame( 403, $this->post( $this->referrals[1], 'approve' )->get_status() );
		$this->assertSame( 403, $this->post( $this->referrals[1], 'reject', [ 'reason' => 'other' ] )->get_status() );
	}

	/** The held referral's first order, completed, so the payout has an order to stand on. */
	private function give_held_referral_a_completed_order(): void {
		global $wpdb;
		$order = wc_create_order();
		$order->set_status( 'completed' );
		$order->set_customer_id( $this->advocate_id + 2 );
		$order->save();
		$wpdb->update( Table::name( 'referrals' ), [ 'first_order_id' => $order->get_id() ], [ 'id' => $this->referrals[1] ] ); // phpcs:ignore WordPress.DB
	}

	/** @return array<int, mixed> The advocate's referral ledger rows. */
	private function referral_rows(): array {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM `' . esc_sql( Table::name( 'ledger' ) ) . '` WHERE user_id = %d AND source = %s', $this->advocate_id, 'referral' ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function post( int $id, string $action, array $body = [] ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', self::ROUTE . '/' . $id . '/' . $action );
		$request->set_body_params( $body );

		return rest_do_request( $request );
	}

	/**
	 * @param array<string, mixed> $params
	 */
	protected function get( array $params ): \WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	protected function user_with( string ...$caps ): int {
		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user    = get_userdata( $user_id );

		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}

		return $user_id;
	}
}
