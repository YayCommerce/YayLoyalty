<?php
namespace YayTests\Unit\Helpers;

use YayLoyalty\Helpers\SettingsHelper;
use YayTests\Unit\TestCase;

/**
 * The settings blob gates earning and expiry, and is validated on read as well as on write.
 *
 * @group baseline
 */
final class SettingsHelperTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		$this->given_settings( [] );
	}

	public function test_validate_drops_unknown_keys_and_coerces_the_known_ones() {
		$clean = SettingsHelper::validate(
			[
				'enabled'               => 'false',
				'unknown'               => 1,
				'points_label_singular' => str_repeat( 'a', 40 ),
				'excluded_roles'        => [ 'Shop Manager', '', 'editor' ],
				'emails'                => [ 'bogus' => 1 ],
			]
		);

		$this->assertSame(
			[
				'enabled'               => false,
				'excluded_roles'        => [ 'shopmanager', 'editor' ],
				'points_label_singular' => str_repeat( 'a', 30 ),
				'emails'                => [],
			],
			$clean
		);
	}

	public function test_an_unreadable_limit_reads_as_no_limit_and_an_unreadable_window_is_dropped() {
		$clean = SettingsHelper::sanitize_output(
			[
				'max_points_per_day' => 0,
				'expiry'             => [ 'mode' => 'bogus', 'inactivity_days' => 36501, 'reminder_days' => 7 ],
			]
		);

		$this->assertArrayHasKey( 'max_points_per_day', $clean );
		$this->assertNull( $clean['max_points_per_day'] );
		$this->assertSame( [ 'reminder_days' => 7 ], $clean['expiry'] );
	}

	/**
	 * @dataProvider invalid_saves
	 */
	public function test_is_valid_refuses_what_coercion_would_silently_change( array $input ) {
		$this->assertFalse( SettingsHelper::is_valid( $input ) );
	}

	public function invalid_saves(): array {
		return [
			'zero daily limit'        => [ [ 'max_points_per_day' => 0 ] ],
			'fractional daily limit'  => [ [ 'max_points_per_day' => 1.5 ] ],
			'window over the maximum' => [ [ 'expiry' => [ 'inactivity_days' => 36501 ] ] ],
			'reminder over a year'    => [ [ 'expiry' => [ 'reminder_days' => 366 ] ] ],
			'unknown spend order'     => [ [ 'expiry' => [ 'spend_order' => 'random' ] ] ],
			'reminder not inside the window while on' => [ [ 'expiry' => [ 'enabled' => true, 'inactivity_days' => 30, 'reminder_days' => 30 ] ] ],
		];
	}

	public function test_is_valid_accepts_no_limit_and_a_reminder_pair_while_expiry_is_off() {
		$this->assertTrue( SettingsHelper::is_valid( [ 'max_points_per_day' => null ] ) );
		$this->assertTrue( SettingsHelper::is_valid( [ 'max_points_per_day' => '5' ] ) );
		$this->assertTrue( SettingsHelper::is_valid( [ 'expiry' => [ 'enabled' => false, 'inactivity_days' => 30, 'reminder_days' => 30 ] ] ) );
	}

	public function test_batch_expiry_is_one_way_once_stored() {
		$this->given_settings( [ 'expiry' => [ 'mode' => 'batch' ] ] );

		$this->assertFalse( SettingsHelper::is_valid( [ 'expiry' => [ 'mode' => 'inactivity' ] ] ) );
		$this->assertTrue( SettingsHelper::is_valid( [ 'expiry' => [ 'mode' => 'batch' ] ] ) );
	}

	public function test_update_saves_only_the_sent_fields_over_what_is_stored() {
		$this->given_settings( [ 'points_label_plural' => 'Stars', 'excluded_roles' => [ 'editor' ], 'expiry' => [ 'inactivity_days' => 90 ] ] );
		$written = null;
		\Brain\Monkey\Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$written ) {
				$written = $value;
				return true;
			}
		);

		SettingsHelper::update_settings( [ 'excluded_roles' => [], 'expiry' => [ 'enabled' => true ] ] );

		$this->assertSame(
			[
				'excluded_roles'      => [],
				'points_label_plural' => 'Stars',
				'expiry'              => [ 'inactivity_days' => 90, 'enabled' => true ],
				'schema_version'      => SettingsHelper::SCHEMA_VERSION,
			],
			$written
		);
	}
}
