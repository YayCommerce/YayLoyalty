<?php
namespace YayTests\Integration\Repositories;

use WP_UnitTestCase;
use YayLoyalty\Controllers\CustomerRowFilters;
use YayLoyalty\Repositories\CustomerRepository;

/**
 * The bulk adjust preview's figures: the points a selection still owes (positive balances only) and whether any
 * balance in it is below zero, for a ticked list and for "select all matching" with rows unticked.
 */
final class CustomerSelectionLiabilityTest extends WP_UnitTestCase {

	private CustomerRepository $customers;

	public function set_up() {
		parent::set_up();
		$this->customers = new CustomerRepository();
	}

	public function test_a_ticked_list_counts_positive_balances_and_flags_a_negative_one() {
		$owed     = $this->customer( 300 );
		$negative = $this->customer( -500 );
		$zero     = $this->customer( 0 );

		$this->assertSame(
			[
				'liability'    => 300,
				'has_negative' => true,
			],
			$this->customers->selection_liability( null, [ $owed, $negative, $zero ], [] )
		);
	}

	public function test_a_ticked_list_without_negative_balances_is_not_flagged() {
		$this->assertSame(
			[
				'liability'    => 200,
				'has_negative' => false,
			],
			$this->customers->selection_liability( null, [ $this->customer( 120 ), $this->customer( 80 ) ], [] )
		);
	}

	public function test_select_all_leaves_out_unticked_rows() {
		$kept     = $this->customer( 250 );
		$negative = $this->customer( -40 );
		$criteria = CustomerRowFilters::to_criteria( [] );

		$with_both    = $this->customers->selection_liability( $criteria, [], [] );
		$without_kept = $this->customers->selection_liability( $criteria, [], [ $kept ] );

		$this->assertTrue( $with_both['has_negative'] );
		$this->assertSame( 250, $with_both['liability'] - $without_kept['liability'] );
		$this->assertSame( $without_kept['liability'], $this->customers->selection_liability( $criteria, [], [ $kept, $negative ] )['liability'] );
	}

	public function test_nothing_selected_is_zero() {
		$this->assertSame(
			[
				'liability'    => 0,
				'has_negative' => false,
			],
			$this->customers->selection_liability( null, [], [] )
		);
	}

	/** A customer row holding $balance, written straight to the table: a fixture, not a ledger movement. */
	private function customer( int $balance ): int {
		global $wpdb;

		$user_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->customers->ensure_row( $user_id );
		$wpdb->update( $this->customers->table(), [ 'balance' => $balance ], [ 'user_id' => $user_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a test fixture.

		return $user_id;
	}
}
