<?php
namespace YayTests\Integration\Pro\Referrals;

use WP_UnitTestCase;
use YayLoyalty\Pro\Models\Referral;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/** A held, paid or rejected referral between one advocate and one friend, and the orders that move it. */
abstract class ReferralTestCase extends WP_UnitTestCase {


	/** @var int */
	protected $advocate_id;

	/** @var int */
	protected $friend_id;

	/** @var int */
	protected $referral_id;

	public function set_up() {
		parent::set_up();
		global $wpdb;

		update_option( ReferralSettings::OPTION, [ 'enabled' => true ] );
		$wpdb->query( 'DELETE FROM `' . esc_sql( Table::name( 'referrals' ) ) . '`' ); // phpcs:ignore WordPress.DB

		$this->advocate_id = self::factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'advocate@example.test',
			]
		);
		$this->friend_id   = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->referral_id = $this->refer( 14 );
	}

	protected function balance( int $user_id ): int {
		$customer = ( new CustomerRepository() )->find_by_user_id( $user_id );

		return null === $customer ? 0 : $customer->get_balance();
	}

	protected function refer( int $hold_days ): int {
		return ( new ReferralRepository() )->insert_pending(
			$this->advocate_id,
			$this->friend_id,
			'TESTREF1',
			[
				'discount_type'         => 'fixed',
				'discount_amount'       => '10',
				'min_order'             => '50',
				'advocate_points'       => 1000,
				'hold_days'             => $hold_days,
				'exclude_other_coupons' => true,
			],
			Dates::offset( 30 * DAY_IN_SECONDS )
		);
	}

	protected function complete_order( float $price, string $billing_email ): int {
		$product = new \WC_Product_Simple();
		$product->set_regular_price( (string) $price );
		$product->save();

		$order = wc_create_order( [ 'customer_id' => $this->friend_id ] );
		$order->add_product( $product, 1 );
		$order->set_billing_email( $billing_email );
		$order->calculate_totals();
		$order->set_date_created( time() - MINUTE_IN_SECONDS );
		$order->save();
		$order->update_status( 'completed' );

		return (int) $order->get_id();
	}

	protected function referral(): Referral {
		$referral = ( new ReferralRepository() )->find( $this->referral_id );
		$this->assertInstanceOf( Referral::class, $referral );

		return $referral;
	}
}
