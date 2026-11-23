<?php
/**
 * Unit test bootstrap. WordPress isn't loaded: stub what the code under test calls with Brain Monkey.
 */

$yay_tests_root = dirname( __DIR__, 3 );
require_once $yay_tests_root . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}

// composer.json autoloads only vendor-prefixed/; the plugin's own classes load the way yayloyalty.php loads them.
spl_autoload_register(
	static function ( string $class_name ) use ( $yay_tests_root ): void {
		if ( 0 !== strncmp( 'YayLoyalty\\', $class_name, 11 ) ) {
			return;
		}

		$file = $yay_tests_root . '/includes/' . str_replace( '\\', '/', substr( $class_name, 11 ) ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

require_once __DIR__ . '/TestCase.php';
