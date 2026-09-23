<?php
/**
 * Integration bootstrap: real WordPress, real MySQL, throwaway tables.
 *
 * The unit suite deliberately has no database, which leaves the invariants
 * that only a server can prove — the UNIQUE idempotency guard firing, row
 * locks, the balance_after chain — as incomplete specifications. This suite
 * is where those become assertions.
 *
 * Isolation is by table prefix, not by database, so no credentials are
 * configured here and no test ever sees the site's own loyalty tables.
 * `Utils\Table::name()` composes from `$wpdb->prefix` on every call, so
 * reassigning that one property moves every plugin table into a scratch
 * namespace this file creates and drops. wpdb's own table properties
 * (`$wpdb->options` and friends) were resolved by `set_prefix()` during load
 * and keep pointing at the site, so WordPress itself carries on working.
 *
 * Point it at a site with:
 *   YAYLOYALTY_WP_ROOT     document root holding wp-load.php (default: five
 *                          levels up, i.e. the site this plugin is installed in)
 *   YAYLOYALTY_TEST_SOCKET mysqld socket, for a server that is not on PHP's
 *                          default one (Local by Flywheel runs one per site)
 */

declare(strict_types=1);

const YAYLOYALTY_TEST_PREFIX = 'ylit_';

/** Stops on a misconfiguration rather than running against unknown tables. */
function yayloyalty_test_bail( string $message ): void {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP_Filesystem is not loaded yet, and this runs before WordPress.
    fwrite( STDERR, 'YayLoyalty integration bootstrap: ' . $message . PHP_EOL );
    exit( 1 );
}

$yayloyalty_test_socket = getenv( 'YAYLOYALTY_TEST_SOCKET' );

if ( is_string( $yayloyalty_test_socket ) && '' !== $yayloyalty_test_socket ) {
    if ( ! file_exists( $yayloyalty_test_socket ) ) {
        yayloyalty_test_bail( sprintf( 'no mysqld socket at %s', $yayloyalty_test_socket ) );
    }

    // phpcs:ignore WordPress.PHP.IniSet.Risky -- the socket has to be set before wp-load opens the connection.
    ini_set( 'mysqli.default_socket', $yayloyalty_test_socket );
}

$yayloyalty_test_root = getenv( 'YAYLOYALTY_WP_ROOT' );
$yayloyalty_test_root = is_string( $yayloyalty_test_root ) && '' !== $yayloyalty_test_root
    ? rtrim( $yayloyalty_test_root, '/' )
    : dirname( __DIR__, 5 );

$yayloyalty_test_wp_load = $yayloyalty_test_root . '/wp-load.php';

if ( ! file_exists( $yayloyalty_test_wp_load ) ) {
    yayloyalty_test_bail(
        sprintf( 'no wp-load.php at %s — set YAYLOYALTY_WP_ROOT to a WordPress document root.', $yayloyalty_test_wp_load )
    );
}

// wp-load reads these even on the command line; without them WordPress warns before it can boot.
$_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['SCRIPT_NAME']    = $_SERVER['SCRIPT_NAME'] ?? '/index.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- a WordPress core constant, which wp-load expects to be set by its caller.
define( 'WP_USE_THEMES', false );

require $yayloyalty_test_wp_load;

global $wpdb;

if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
    yayloyalty_test_bail( 'WordPress loaded without a $wpdb.' );
}

if ( ! class_exists( \YayLoyalty\Database\Installer::class ) ) {
    yayloyalty_test_bail( 'the plugin is not active on this site, so its classes never loaded.' );
}

if ( YAYLOYALTY_TEST_PREFIX === $wpdb->prefix ) {
    yayloyalty_test_bail(
        sprintf( 'the site itself uses the scratch prefix "%s"; nothing here would be throwaway.', YAYLOYALTY_TEST_PREFIX )
    );
}

$wpdb->prefix = YAYLOYALTY_TEST_PREFIX;

/**
 * Every plugin table, by its scratch name.
 *
 * @return string[]
 */
function yayloyalty_test_tables(): array {
    $tables = [];

    foreach ( \YayLoyalty\Database\Installer::schemas() as $schema ) {
        $name = \YayLoyalty\Utils\Table::name( $schema->slug() );

        // The prefix swap is the only thing standing between a DROP and real data.
        if ( 0 === strpos( $name, YAYLOYALTY_TEST_PREFIX ) ) {
            $tables[] = $name;
        }
    }

    return $tables;
}

function yayloyalty_test_drop_tables(): void {
    global $wpdb;

    foreach ( yayloyalty_test_tables() as $table ) {
        $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB
    }
}

yayloyalty_test_drop_tables();

foreach ( \YayLoyalty\Database\Installer::schemas() as $yayloyalty_test_schema ) {
    if ( false === $wpdb->query( $yayloyalty_test_schema->definition() ) ) { // phpcs:ignore WordPress.DB
        yayloyalty_test_bail(
            sprintf( 'could not create the %s table: %s', $yayloyalty_test_schema->slug(), $wpdb->last_error )
        );
    }
}

register_shutdown_function( 'yayloyalty_test_drop_tables' );

require_once __DIR__ . '/IntegrationTestCase.php';
