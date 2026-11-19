<?php
namespace YayTests\Unit\Pro\Referrals;

use Brain\Monkey;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayTests\Unit\TestCase;

/**
 * The referral settings set what every new referral is promised; a bad value must never be stored or read back.
 *
 * @group P-3
 */
final class ReferralSettingsTest extends TestCase {

	/** @var mixed */
	private $stored = [];

	protected function set_up() {
		parent::set_up();
		$this->stored = [];

		Monkey\Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return ReferralSettings::OPTION === $name ? $this->stored : $fallback;
			}
		);
		Monkey\Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->stored = $value;

				return true;
			}
		);
		Monkey\Functions\when( 'wc_format_decimal' )->alias(
			static function ( $value ) {
				return rtrim( rtrim( number_format( (float) $value, 4, '.', '' ), '0' ), '.' );
			}
		);
	}

	public function test_defaults_apply_when_nothing_is_stored() {
		$settings = ReferralSettings::get();

		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( 'fixed', $settings['discount_type'] );
		$this->assertSame( '10', $settings['discount_amount'] );
		$this->assertSame( 1000, $settings['advocate_points'] );
		$this->assertSame( 14, $settings['hold_days'] );
		$this->assertSame( 30, $settings['cookie_days'] );
	}

	/**
	 * @dataProvider out_of_range
	 */
	public function test_an_out_of_range_field_is_named( array $input, string $field ) {
		$this->assertSame( $field, ReferralSettings::invalid_field( $input ) );
	}

	public function out_of_range(): array {
		return [
			'percentage over 100'   => [
				[
					'discount_type'   => 'percent',
					'discount_amount' => 150,
				],
				'discount_amount',
			],
			'zero discount'         => [ [ 'discount_amount' => 0 ], 'discount_amount' ],
			'percentage under 1'    => [
				[
					'discount_type'   => 'percent',
					'discount_amount' => '0.5',
				],
				'discount_amount',
			],
			'zero points'           => [ [ 'advocate_points' => 0 ], 'advocate_points' ],
			'hold over 90 days'     => [ [ 'hold_days' => 91 ], 'hold_days' ],
			'cookie of 0 days'      => [ [ 'cookie_days' => 0 ], 'cookie_days' ],
			'coupon over 365 days'  => [ [ 'coupon_days' => 366 ], 'coupon_days' ],
			'unknown discount type' => [ [ 'discount_type' => 'free' ], 'discount_type' ],
			'exponent notation'     => [ [ 'discount_amount' => '1e3' ], 'discount_amount' ],
			'oversized points'      => [ [ 'advocate_points' => '1e19' ], 'advocate_points' ],
		];
	}

	public function test_switching_to_percent_is_judged_against_the_stored_amount() {
		$this->stored = [ 'discount_amount' => '150' ];

		$this->assertSame( 'discount_amount', ReferralSettings::invalid_field( [ 'discount_type' => 'percent' ] ) );
		$this->assertNull( ReferralSettings::invalid_field( [ 'discount_type' => 'percent', 'discount_amount' => 20 ] ) );
	}

	public function test_update_keeps_money_as_a_string_and_drops_unknown_keys() {
		$saved = ReferralSettings::update(
			[
				'min_order' => 50,
				'hold_days' => '7',
				'bogus'     => 1,
			]
		);

		$this->assertSame( '50', $saved['min_order'] );
		$this->assertSame( 7, $saved['hold_days'] );
		$this->assertArrayNotHasKey( 'bogus', $this->stored );
		$this->assertSame( '50', $this->stored['min_order'] );
	}

	public function test_a_stored_value_that_no_longer_fits_reads_as_the_default() {
		$this->stored = [
			'hold_days'       => 500,
			'discount_type'   => 'percent',
			'discount_amount' => '150',
		];

		$settings = ReferralSettings::get();

		$this->assertSame( 14, $settings['hold_days'] );
		$this->assertSame( '10', $settings['discount_amount'] );
	}
}
