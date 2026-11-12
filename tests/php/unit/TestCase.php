<?php
namespace YayTests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use YayLoyalty\Helpers\SettingsHelper;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Base for unit tests: Brain Monkey on, translation and escaping functions return their input,
 * and apply_filters() returns the value it filters, as on a site with nothing hooked (Lite).
 */
abstract class TestCase extends PolyfillTestCase {
	use MockeryPHPUnitIntegration;

	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();
	}

	protected function tear_down() {
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Stores $saved as the `yayloyalty_settings` option and clears SettingsHelper's per-request cache.
	 *
	 * @param mixed $saved The raw option value.
	 */
	protected function given_settings( $saved ): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $saved ) {
				return SettingsHelper::OPTION_SETTINGS === $name ? $saved : $fallback;
			}
		);
		Monkey\Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Monkey\Functions\when( 'rest_sanitize_boolean' )->alias(
			static function ( $value ) {
				return is_string( $value ) ? ! in_array( strtolower( $value ), [ 'false', '0', '' ], true ) : (bool) $value;
			}
		);
		Monkey\Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Monkey\Functions\when( 'sanitize_text_field' )->alias( 'trim' );

		$cache = new \ReflectionProperty( SettingsHelper::class, 'cache' );
		$cache->setAccessible( true );
		$cache->setValue( null, null );
	}

	/** Makes batch a mode this build runs, as Pro does through `yayloyalty_expiry_modes`. */
	protected function given_batch_mode_is_available(): void {
		Monkey\Filters\expectApplied( 'yayloyalty_expiry_modes' )->zeroOrMoreTimes()->andReturn( [ 'inactivity', 'batch' ] );
	}
}
