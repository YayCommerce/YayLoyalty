<?php
namespace YayTests\Integration\Pro\Storefront;

use WP_UnitTestCase;
use YayLoyalty\Pro\Referrals\ReferralEarnRow;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Pro\Storefront\AccountReferrals;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * My Account → Referrals: the offer, a stable link, the counts and the friends' statuses in the customer's words.
 *
 * @group P-6
 */
final class AccountReferralsTest extends WP_UnitTestCase {

	/** @var int */
	private $advocate_id;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB
		update_option(
			ReferralSettings::OPTION,
			[
				'enabled'         => true,
				'discount_type'   => 'fixed',
				'discount_amount' => '10',
				'min_order'       => '50',
				'advocate_points' => 1000,
				'hold_days'       => 14,
			]
		);
		$this->advocate_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		wp_set_current_user( $this->advocate_id );
	}

	public function test_the_tab_shows_the_offer_a_stable_link_and_the_statuses_in_the_customers_words() {
		$repository = new ReferralRepository();
		$held       = $repository->insert_pending( $this->advocate_id, $this->friend( 'held@example.test' ), 'X', [ 'advocate_points' => 1000 ], Dates::offset( DAY_IN_SECONDS ) );
		$repository->hold( $held, 77, Dates::offset( 5 * DAY_IN_SECONDS ) );
		$repository->insert_pending( $this->advocate_id, $this->friend( 'pending@example.test' ), 'X', [ 'advocate_points' => 1000 ], Dates::offset( DAY_IN_SECONDS ) );

		$first  = $this->render();
		$second = $this->render();
		$code   = ( new CustomerRepository() )->assign_referral_code( $this->advocate_id );

		$this->assertStringContainsString( 'Give $10, get 1,000', $first );
		$this->assertStringContainsString( '?yl_ref=' . $code, $first );
		$this->assertStringContainsString( '?yl_ref=' . $code, $second );
		$this->assertStringContainsString( 'h•••@example.test', $first );
		$this->assertStringNotContainsString( 'held@example.test', $first );
		$this->assertStringContainsString( 'On the way · arrives', $first );
		$this->assertStringContainsString( 'Signed up · waiting on a first order', $first );
	}

	public function test_with_the_program_off_there_is_no_menu_item_and_no_tab() {
		update_option( ReferralSettings::OPTION, [ 'enabled' => false ] );

		$this->assertArrayNotHasKey( AccountReferrals::ENDPOINT, AccountReferrals::add_menu_item( [ 'dashboard' => 'Dashboard' ] ) );
		$this->assertSame( [], ( new ReferralEarnRow() )->add_row( [], [], $this->advocate_id ) );
		$this->assertSame( '', $this->render() );
	}

	public function test_ways_to_earn_offers_the_referral_and_links_to_the_tab() {
		$rows = ( new ReferralEarnRow() )->add_row( [], [], $this->advocate_id );

		$this->assertSame( 'Refer a friend', $rows[0]['label'] );
		$this->assertSame( AccountReferrals::url(), $rows[0]['url'] );
	}

	public function test_two_customers_never_share_a_code() {
		$customers = new CustomerRepository();
		$other     = self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertNotSame( $customers->assign_referral_code( $this->advocate_id ), $customers->assign_referral_code( $other ) );
	}

	private function render(): string {
		ob_start();
		AccountReferrals::render();

		return (string) ob_get_clean();
	}

	private function friend( string $email ): int {
		return self::factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => $email,
			]
		);
	}
}
