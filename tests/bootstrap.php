<?php
/**
 * Unit-test bootstrap. NO WordPress.
 *
 * The points engine is pure: it reads campaigns through a repository and
 * otherwise touches only values on the Context. The only WordPress symbols it
 * reaches are `defined( 'ABSPATH' )` guards and the hook functions its
 * documented filters go through, so those are shimmed here — a real WP
 * bootstrap would cost seconds per run and prove nothing extra about
 * arithmetic.
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
