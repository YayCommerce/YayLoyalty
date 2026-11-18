<?php
namespace YayTests\Unit\Pro\Referrals;

use Brain\Monkey;
use YayLoyalty\Pro\Enums\ReferralRejectReason;
use YayLoyalty\Pro\Referrals\ReferralQualifier;
use YayTests\Unit\TestCase;

/**
 * A first order at or over the minimum, without another coupon when excluded, from someone other than the advocate, pays the advocate.
 *
 * @group P-4
 */
final class ReferralQualifierTest extends TestCase {

	private const OWN_CODE = 'yl-referral-7';

	private const TERMS = [
		'min_order'             => '50',
		'exclude_other_coupons' => true,
	];

	protected function set_up() {
		parent::set_up();
		Monkey\Functions\when( 'get_option' )->justReturn( 2 );
	}

	public function test_an_order_a_rate_conversion_leaves_a_hair_under_the_minimum_qualifies() {
		$this->assertNull( $this->check( 55 / 1.1, [ self::OWN_CODE ], 'friend@example.test' ) );
	}

	public function test_a_first_order_at_the_minimum_with_its_own_and_a_loyalty_code_qualifies() {
		$this->assertNull( $this->check( 50.0, [ self::OWN_CODE, 'yayloyalty-abcd1234' ], 'friend@example.test' ) );
	}

	public function test_an_order_under_the_minimum_does_not_qualify() {
		$this->assertSame( ReferralRejectReason::NOT_QUALIFYING, $this->check( 49.99, [ self::OWN_CODE ], 'friend@example.test' ) );
	}

	public function test_another_coupon_does_not_qualify_while_excluded() {
		$this->assertSame( ReferralRejectReason::NOT_QUALIFYING, $this->check( 60.0, [ self::OWN_CODE, 'summer10' ], 'friend@example.test' ) );
	}

	public function test_another_coupon_qualifies_when_not_excluded() {
		$terms = [ 'exclude_other_coupons' => false ] + self::TERMS;

		$this->assertNull( ReferralQualifier::check( $terms, 60.0, [ 'summer10' ], self::OWN_CODE, 'friend@example.test', 'advocate@example.test' ) );
	}

	public function test_the_advocates_billing_email_is_a_self_referral() {
		$this->assertSame( ReferralRejectReason::SELF_REFERRAL, $this->check( 60.0, [], ' Advocate@Example.test ' ) );
	}

	private function check( float $subtotal, array $codes, string $billing_email ): ?string {
		return ReferralQualifier::check( self::TERMS, $subtotal, $codes, self::OWN_CODE, $billing_email, 'advocate@example.test' );
	}
}
