<?php
/**
 * Unit-test bootstrap. NO WordPress.
 *
 * The suite under `tests/unit/` is plain PHP: the points engine, PointsMath's
 * shared vectors and the dashboard services (Dashboard/) all read only values
 * passed to them and a repository interface, never `$wpdb` or WooCommerce
 * globals directly. The only WordPress symbols any of it reaches are
 * `defined( 'ABSPATH' )` guards and the hook functions its documented filters
 * go through, so those are shimmed here — a real WP bootstrap would cost
 * seconds per run and prove nothing extra about arithmetic.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

// Same mapping as yayloyalty.php: YayLoyalty\* → includes/*. PHPUnit's own
// classes come from Composer's autoloader, loaded by the phpunit binary.
spl_autoload_register(
    static function ( string $class_name ): void {
        $prefix = 'YayLoyalty\\';

        if ( 0 !== strncmp( $prefix, $class_name, strlen( $prefix ) ) ) {
            return;
        }

        $file = __DIR__ . '/../includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

        if ( file_exists( $file ) ) {
            require $file;
        }
    }
);

require_once __DIR__ . '/unit/EngineTestCase.php';

/*
 * Minimal hook shim: enough for a test to attach a callback and assert the
 * engine's filters fire with the documented arguments. Priority and
 * accepted-args semantics are simplified — every callback gets every arg.
 */
$GLOBALS['yayloyalty_test_hooks'] = [];

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    $GLOBALS['yayloyalty_test_hooks'][ $hook ][] = $callback;

    return true;
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
    return add_filter( $hook, $callback, $priority, $accepted_args );
}

/**
 * @param mixed $value
 * @param mixed ...$args
 * @return mixed
 */
function apply_filters( string $hook, $value, ...$args ) {
    foreach ( $GLOBALS['yayloyalty_test_hooks'][ $hook ] ?? [] as $callback ) {
        $value = $callback( $value, ...$args );
    }

    return $value;
}

/**
 * @param mixed ...$args
 */
function do_action( string $hook, ...$args ): void {
    foreach ( $GLOBALS['yayloyalty_test_hooks'][ $hook ] ?? [] as $callback ) {
        $callback( ...$args );
    }
}

/*
 * In-memory options store: DesignHelper and DesignCoercion reach get_option(),
 * update_option(), delete_option() and the sanitisers they depend on
 * (sanitize_text_field, sanitize_textarea_field, sanitize_key, sanitize_hex_color,
 * absint, wp_get_attachment_url). Shimmed here to avoid a full WordPress bootstrap
 * while proving the coercion rules; a real WP harness later would prove integration.
 * Every function is guarded with function_exists() so it doesn't collide with a
 * future real-WP test environment.
 */
$GLOBALS['yayloyalty_test_options'] = [];
$GLOBALS['yayloyalty_test_current_user_id'] = 0;

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $option, $default = false ) {
        return $GLOBALS['yayloyalty_test_options'][ $option ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $option, $value, string $deprecated = '' ): bool {
        $GLOBALS['yayloyalty_test_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $option ): bool {
        unset( $GLOBALS['yayloyalty_test_options'][ $option ] );
        return true;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( $str );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( string $str ): string {
        return trim( $str );
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string {
        return trim( $key );
    }
}

if ( ! function_exists( 'sanitize_hex_color' ) ) {
    // Mirrors wp-includes/formatting.php: 3 or 6 hex digits, hash required, case preserved
    // (real WordPress does not lowercase). '' stands in for WP's null on a failed match.
    function sanitize_hex_color( string $color ): string {
        $color = trim( $color );
        if ( '' === $color ) {
            return '';
        }
        return preg_match( '/^#([0-9a-f]{3}){1,2}$/i', $color ) ? $color : '';
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( int $attachment_id ): string {
        return '';
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id(): int {
        return $GLOBALS['yayloyalty_test_current_user_id'] ?? 0;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}
