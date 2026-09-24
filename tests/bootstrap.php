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
 *
 * `CustomerActivityServiceTest` additionally reaches `wp_json_encode()`
 * (via `Utils\Json`), `get_userdata()` and `get_current_user_id()` — three
 * one-line shims below, backed by test-controlled globals, never a real
 * WP_User or database.
 *
 * `DesignHelperTest` reaches the options API and WordPress's sanitisers: the
 * options live in `$GLOBALS['yayloyalty_test_options']`, and the sanitisers
 * are close-enough approximations of core's (the tests assert the coercion
 * rules, not core's exact character handling).
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

/**
 * WordPress wraps json_encode() with UTF-8 sanitization this suite never
 * exercises with malformed input; the encode path (Utils\Json, reached by
 * Campaign::to_row()) only needs the same return contract.
 *
 * @param mixed $data
 * @return string|false
 */
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
    return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

/** Minimal double: only the two properties this codebase ever reads off a WP_User. */
if ( ! class_exists( 'WP_User' ) ) {
    class WP_User {
        /** @var string */
        public $display_name;

        /** @var string */
        public $user_email;

        public function __construct( string $display_name = '', string $user_email = '' ) {
            $this->display_name = $display_name;
            $this->user_email   = $user_email;
        }
    }
}

/** Tests set $GLOBALS['yayloyalty_test_users'][$id] to a WP_User instance (or leave unset for "no such user"). */
function get_userdata( int $user_id ) {
    return $GLOBALS['yayloyalty_test_users'][ $user_id ] ?? false;
}

function get_current_user_id(): int {
    return $GLOBALS['yayloyalty_test_current_user'] ?? 0;
}

/** Tests reset $GLOBALS['yayloyalty_test_options'] in setUp(). */
function get_option( string $option, $default_value = false ) {
    return $GLOBALS['yayloyalty_test_options'][ $option ] ?? $default_value;
}

function update_option( string $option, $value, $autoload = null ): bool {
    $GLOBALS['yayloyalty_test_options'][ $option ] = $value;
    return true;
}

function delete_option( string $option ): bool {
    unset( $GLOBALS['yayloyalty_test_options'][ $option ] );
    return true;
}

function __( string $text, string $domain = 'default' ): string {
    return $text;
}

function sanitize_key( $key ): string {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_hex_color( string $color ): ?string {
    return 1 === preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : null;
}

function sanitize_text_field( string $str ): string {
    return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $str ) ) );
}

function sanitize_textarea_field( string $str ): string {
    return trim( strip_tags( $str ) );
}

function absint( $maybeint ): int {
    return abs( (int) $maybeint );
}

/** Same as core: the strings "false" and "0", in any case, are false. */
function rest_sanitize_boolean( $value ): bool {
    if ( is_string( $value ) && in_array( strtolower( $value ), [ 'false', '0' ], true ) ) {
        return false;
    }

    return (bool) $value;
}

function wp_get_attachment_url( int $attachment_id ) {
    return false;
}
