<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
use YayLoyalty\Pro\Referrals\ReferralAttribution;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Utils\Table;

/**
 * Only a visitor who registers themself through a live link becomes a referral, once, with the terms in force then.
 *
 * @group P-3
 */
final class ReferralAttributionTest extends WP_UnitTestCase {

	private const CODE = 'TESTREF1';

	/** @var int */
	private $advocate_id;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option(
			ReferralSettings::OPTION,
			[
				'enabled'         => true,
				'advocate_points' => 1000,
				'coupon_days'     => 30,
			]
		);

		wp_set_current_user( 0 );
		$this->advocate_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$wpdb->update( Table::name( 'customers' ), [ 'referral_code' => self::CODE ], [ 'user_id' => $this->advocate_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB

		$_COOKIE[ ReferralAttribution::COOKIE ] = self::CODE . '.' . time();
	}

	public function tear_down() {
		unset( $_COOKIE[ ReferralAttribution::COOKIE ] );
		parent::tear_down();
	}

	public function test_a_self_registration_through_a_live_link_is_one_pending_referral_with_its_terms() {
		$created = [];
		add_action(
			\YayLoyalty\Pro\Referrals\ReferralAttribution::CREATED_ACTION,
			static function ( int $referral_id, int $friend ) use ( &$created ): void {
				$created[] = $friend;
			},
			10,
			2
		);
		$friend_id = self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( [ $friend_id ], $created );

		$rows = $this->referrals();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'pending', $rows[0]['status'] );
		$this->assertSame( $this->advocate_id, (int) $rows[0]['advocate_user_id'] );
		$this->assertSame( $friend_id, (int) $rows[0]['friend_user_id'] );
		$this->assertSame( self::CODE, $rows[0]['referral_code'] );
		$this->assertSame( 1000, json_decode( $rows[0]['terms'], true )['advocate_points'] );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, strtotime( $rows[0]['coupon_expires_at'] . ' UTC' ), 60 );
	}

	public function test_a_repeated_user_register_for_the_same_friend_adds_nothing() {
		$friend_id = self::factory()->user->create( [ 'role' => 'customer' ] );

		do_action( 'user_register', $friend_id, [] );

		$this->assertCount( 1, $this->referrals() );
	}

	public function test_an_account_created_by_an_admin_is_not_a_referral() {
		wp_set_current_user( 1 );
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( [], $this->referrals() );
	}

	public function test_an_unknown_or_malformed_code_is_not_a_referral() {
		$_COOKIE[ ReferralAttribution::COOKIE ] = 'NOSUCHCODE.' . time();
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$_COOKIE[ ReferralAttribution::COOKIE ] = "x' OR 1=1 --." . time();
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( [], $this->referrals() );
	}

	public function test_a_deleted_advocate_s_link_is_not_a_referral() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->advocate_id );
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( [], $this->referrals() );
	}

	public function test_a_code_in_another_case_is_stored_as_the_advocate_s_code() {
		$_COOKIE[ ReferralAttribution::COOKIE ] = strtolower( self::CODE ) . '.' . time();
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( self::CODE, $this->referrals()[0]['referral_code'] );
	}

	public function test_a_visit_older_than_today_s_cookie_window_is_not_a_referral() {
		update_option( ReferralSettings::OPTION, [ 'enabled' => true, 'cookie_days' => 7 ] );
		$_COOKIE[ ReferralAttribution::COOKIE ] = self::CODE . '.' . ( time() - 20 * DAY_IN_SECONDS );
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( [], $this->referrals() );
	}

	public function test_a_cookie_without_a_visit_time_is_not_a_referral() {
		$_COOKIE[ ReferralAttribution::COOKIE ] = self::CODE;
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( [], $this->referrals() );
	}

	public function test_nothing_is_attributed_while_the_program_is_off() {
		update_option( ReferralSettings::OPTION, [ 'enabled' => false ] );
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertSame( [], $this->referrals() );
	}

	/** @return array<int, array<string, string>> */
	private function referrals(): array {
		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM `' . esc_sql( Table::name( 'referrals' ) ) . '` ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
