<?php
namespace YayTests\Integration\Controllers;

use WP_REST_Request;
use WP_UnitTestCase;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;

/**
 * Tools → Import balances: only `run_tools` may import, the preview reports each row, and apply writes only valid rows.
 *
 * @group P-8
 */
final class ToolsImportRestControllerTest extends WP_UnitTestCase {

	private const IMPORT_ID = 123456789;

	/** @var int */
	private $customer_id;

	public function set_up() {
		parent::set_up();

		// Created by an admin, so the built-in signup campaign (self-registration only) awards nothing.
		wp_set_current_user( 1 );
		$this->customer_id = self::factory()->user->create( [ 'user_email' => 'ann@example.com' ] );
	}

	public function test_without_run_tools_both_routes_are_forbidden() {
		$user_id = self::factory()->user->create( [ 'role' => 'shop_manager' ] );
		wp_set_current_user( $user_id );

		$this->assertSame( 403, $this->post( 'preview', [ $this->row( 2, 'ann@example.com', '10' ) ] )->get_status() );
		$this->assertSame( 403, $this->post( 'apply', [ $this->row( 2, 'ann@example.com', '10' ) ] )->get_status() );
	}

	public function test_preview_reports_each_row_and_writes_nothing() {
		$response = $this->post(
			'preview',
			[
				$this->row( 2, 'ann@example.com', '+100' ),
				$this->row( 3, 'nobody@example.com', '10' ),
				$this->row( 4, 'ann@example.com', '1.5' ),
				$this->row( 5, 'ann@example.com', '0' ),
				$this->row( 6, 'ann@example.com', '-5' ),
			]
		);
		$rows     = array_column( $response->get_data()['rows'], null, 'row' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ready', $rows[2]['status'] );
		$this->assertSame( 100, $rows[2]['balance_after'] );
		$this->assertSame( 'unknown_email', $rows[3]['reason'] );
		$this->assertSame( 'invalid_points', $rows[4]['reason'] );
		$this->assertSame( 'invalid_points', $rows[5]['reason'] );
		$this->assertSame( 'would_go_negative', $rows[6]['reason'] );
		$this->assertSame( 0, $this->balance() );
	}

	public function test_apply_writes_valid_rows_and_a_second_run_reports_them_imported() {
		$applied = array_column( $this->post( 'apply', [ $this->row( 2, 'ann@example.com', '100' ), $this->row( 3, 'nobody@example.com', '10' ) ] )->get_data()['rows'], null, 'row' );

		$this->assertSame( 'imported', $applied[2]['status'] );
		$this->assertSame( 'error', $applied[3]['status'] );
		$this->assertSame( 100, $this->balance() );
		$this->assertStringContainsString( 'old-plugin.csv', ( new LedgerRepository() )->latest_for_user( $this->customer_id, 1 )[0]->get_note() );

		$again   = $this->post( 'apply', [ $this->row( 2, 'ann@example.com', '100' ) ] )->get_data()['rows'][0];
		$preview = $this->post( 'preview', [ $this->row( 2, 'ann@example.com', '100' ) ] )->get_data()['rows'][0];

		$this->assertSame( 'duplicate', $again['status'] );
		$this->assertSame( 'already_imported', $preview['status'] );
		$this->assertSame( 100, $this->balance() );
	}

	public function test_preview_flags_a_customer_imported_from_another_file() {
		$this->post( 'apply', [ $this->row( 2, 'ann@example.com', '100' ) ] );

		$preview = $this->post( 'preview', [ $this->row( 2, 'ann@example.com', '100' ) ], self::IMPORT_ID + 1 )->get_data()['rows'][0];

		$this->assertSame( 'imported_before', $preview['status'] );
		$this->assertNotEmpty( $preview['imported_at'] );
		$this->assertSame( 200, $preview['balance_after'] );
	}

	public function test_a_row_refused_inside_the_ledger_does_not_undo_the_row_before_it() {
		self::factory()->user->create( [ 'user_email' => 'bob@example.com' ] );

		$applied = array_column( $this->post( 'apply', [ $this->row( 2, 'ann@example.com', '100' ), $this->row( 3, 'bob@example.com', '-999' ) ] )->get_data()['rows'], null, 'row' );

		$this->assertSame( 'imported', $applied[2]['status'] );
		$this->assertSame( 'skipped', $applied[3]['status'] );
		$this->assertSame( 'would_go_negative', $applied[3]['reason'] );
		$this->assertSame( 100, $this->balance() );
	}

	private function post( string $action, array $rows, int $import_id = self::IMPORT_ID ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/yayloyalty/v1/tools/import/' . $action );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'import_id' => $import_id,
					'file_name' => 'old-plugin.csv',
					'rows'      => $rows,
				]
			)
		);

		return rest_do_request( $request );
	}

	private function row( int $row, string $email, string $points ): array {
		return [
			'row'    => $row,
			'email'  => $email,
			'points' => $points,
		];
	}

	private function balance(): int {
		$customer = ( new CustomerRepository() )->find_by_user_id( $this->customer_id );

		return null !== $customer ? $customer->get_balance() : 0;
	}
}
