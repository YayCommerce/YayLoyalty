<?php
/**
 * Integration test bootstrap: the WordPress test suite that wp-env mounts at WP_TESTS_DIR, in the tests site
 * (its own database, so the e2e site's data is never touched).
 */

$yay_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/wordpress-phpunit';
if ( ! file_exists( $yay_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "No WordPress test suite at $yay_tests_dir. Run integration tests with: yayh test run integration\n" );
	exit( 1 );
}

$yay_tests_root = dirname( __DIR__, 3 );
require_once $yay_tests_root . '/vendor/autoload.php';
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $yay_tests_root . '/vendor/yoast/phpunit-polyfills' );

require_once $yay_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $yay_tests_root ) {
		require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		require_once $yay_tests_root . '/yayloyalty.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles WooCommerce just added.
		wp_roles();
	}
);

require_once $yay_tests_dir . '/includes/bootstrap.php';
