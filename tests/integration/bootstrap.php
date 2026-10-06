<?php
/**
 * Integration bootstrap: real WordPress, real MySQL, throwaway tables. It proves what only a
 * server can, such as the UNIQUE idempotency guard, row locks and the balance_after chain.
 *
 * Isolation is by table prefix, not by database, so no test sees the site's own loyalty tables.
 * `Utils\Table::name()` reads `$wpdb->prefix` on every call, so reassigning it moves every plugin
 * table under a scratch prefix that this file creates and drops. wpdb's own table properties,
 * such as `$wpdb->options`, were resolved at load and keep pointing at the site.
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

// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- booting the whole REST server, WooCommerce's routes included, outgrows the CLI's 128M.
ini_set( 'memory_limit', '512M' );

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
 * Every site table outside WordPress core and this plugin, site name =>
 * scratch name. Other plugins (WooCommerce's HPOS orders, Action Scheduler,
 * review and mail plugins reacting to an order) write wherever `$wpdb`
 * points, so each gets an empty copy under the scratch prefix: a test can
 * then drive real WooCommerce hooks without touching site data.
 *
 * @return array<string, string>
 */
function yayloyalty_test_site_tables(): array {
    global $wpdb;

    // wpdb's own declarations, before any plugin appended to $wpdb->tables.
    $defaults = ( new \ReflectionClass( $wpdb ) )->getDefaultProperties();
    $core     = [];

    foreach ( [ 'tables', 'old_tables', 'global_tables', 'ms_global_tables' ] as $group ) {
        foreach ( (array) ( $defaults[ $group ] ?? [] ) as $table ) {
            $core[ $wpdb->base_prefix . $table ] = true;
        }
    }

    $own    = $wpdb->base_prefix . \YayLoyalty\Utils\Table::PREFIX;
    $tables = [];

    foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) ) as $site_table ) { // phpcs:ignore WordPress.DB -- listing tables has no WordPress API; the pattern is prepared.
        if ( isset( $core[ $site_table ] ) || 0 === strpos( $site_table, $own ) || 0 === strpos( $site_table, YAYLOYALTY_TEST_PREFIX ) ) {
            continue;
        }

        $tables[ $site_table ] = YAYLOYALTY_TEST_PREFIX . substr( $site_table, strlen( $wpdb->base_prefix ) );
    }

    return $tables;
}

/**
 * Every scratch table: the site-table copies, then the plugin's.
 *
 * @return string[]
 */
function yayloyalty_test_tables(): array {
    $tables = array_values( yayloyalty_test_site_tables() );

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
        $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB -- dropping a scratch table has no WordPress API; the name is escaped.
    }
}

yayloyalty_test_drop_tables();

foreach ( \YayLoyalty\Database\Installer::schemas() as $yayloyalty_test_schema ) {
    if ( false === $wpdb->query( $yayloyalty_test_schema->definition() ) ) { // phpcs:ignore WordPress.DB -- the plugin's own CREATE TABLE statement.
        yayloyalty_test_bail(
            sprintf( 'could not create the %s table: %s', $yayloyalty_test_schema->slug(), $wpdb->last_error )
        );
    }
}

foreach ( yayloyalty_test_site_tables() as $yayloyalty_test_site_table => $yayloyalty_test_scratch_table ) {
    if ( false === $wpdb->query( 'CREATE TABLE `' . esc_sql( $yayloyalty_test_scratch_table ) . '` LIKE `' . esc_sql( $yayloyalty_test_site_table ) . '`' ) ) { // phpcs:ignore WordPress.DB -- copying a table has no WordPress API; the names are escaped.
        yayloyalty_test_bail( sprintf( 'could not copy %s: %s', $yayloyalty_test_site_table, $wpdb->last_error ) );
    }

    // Order item meta, Action Scheduler and friends go through $wpdb properties set at load; point them at the copy too.
    foreach ( get_object_vars( $wpdb ) as $yayloyalty_test_property => $yayloyalty_test_value ) {
        if ( $yayloyalty_test_site_table === $yayloyalty_test_value ) {
            $wpdb->$yayloyalty_test_property = $yayloyalty_test_scratch_table;
        }
    }
}

register_shutdown_function( 'yayloyalty_test_drop_tables' );

require_once __DIR__ . '/IntegrationTestCase.php';
