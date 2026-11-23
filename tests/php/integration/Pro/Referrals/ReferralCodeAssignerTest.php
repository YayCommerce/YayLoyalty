<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Table;

/**
 * A new customer has a referral code as soon as their row exists; an existing row is left alone.
 *
 * @group P-6
 */
final class ReferralCodeAssignerTest extends WP_UnitTestCase {

	public function test_a_new_customer_gets_a_code_when_their_row_is_created() {
		$user_id = self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{8}$/', (string) $this->code( $user_id ) );
	}

	public function test_a_row_first_created_by_a_ledger_touch_gets_a_code() {
		global $wpdb;
		$user_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$wpdb->delete( Table::name( 'customers' ), [ 'user_id' => $user_id ] ); // phpcs:ignore WordPress.DB

		( new CustomerRepository() )->ensure_row( $user_id );

		$this->assertNotNull( $this->code( $user_id ) );
	}

	public function test_an_existing_row_without_a_code_is_not_touched_by_ensure_row() {
		global $wpdb;
		$user_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$wpdb->query( $wpdb->prepare( 'UPDATE `' . esc_sql( Table::name( 'customers' ) ) . '` SET referral_code = NULL WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB

		( new CustomerRepository() )->ensure_row( $user_id );

		$this->assertNull( $this->code( $user_id ) );
	}

	private function code( int $user_id ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SELECT referral_code FROM `' . esc_sql( Table::name( 'customers' ) ) . '` WHERE user_id = %d', $user_id ) ); // phpcs:ignore WordPress.DB
	}
}
