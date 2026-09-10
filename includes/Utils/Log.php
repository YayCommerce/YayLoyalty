<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin logging.
 *
 * Writes to WooCommerce's logger when it is available, so entries land in
 * WooCommerce → Status → Logs where a merchant can read them and support can
 * ask for them. Falls back to error_log() when WooCommerce has not loaded yet —
 * activation and early bootstrap run before it does.
 *
 * LEVEL DECIDES WHETHER WP_DEBUG MATTERS, and that is the whole point of having
 * levels here:
 *
 *   error()   — always written. A failure a site owner needs a record of.
 *   warning() — always written. Something is misconfigured but the request
 *               survived.
 *   debug()   — WP_DEBUG only. Diagnostic detail, safe to be noisy.
 *
 * Guarding errors behind WP_DEBUG is the mistake this replaces. A production
 * site has WP_DEBUG off by definition, so the correlation id a 500 hands the
 * user referenced a log line that was never written — the one moment the log
 * exists for.
 *
 * $context is the class or subsystem reporting, not a data payload: 'Installer',
 * 'RestAPI', 'LedgerService'. It becomes the line's prefix.
 */
final class Log {

    /** WooCommerce log source; also the file name under wp-content/uploads/wc-logs. */
    public const SOURCE = 'yayloyalty';

    /**
     * A failure worth a permanent record: a failed install, an unhandled
     * exception, a write that did not happen.
     */
    public static function error( string $context, string $message ): void {
        self::write( 'error', $context, $message );
    }

    /**
     * Something is wrong but the request continued — a bad filter subscriber,
     * a config the plugin had to ignore.
     */
    public static function warning( string $context, string $message ): void {
        self::write( 'warning', $context, $message );
    }

    /**
     * Diagnostic detail. Silent unless WP_DEBUG is on, so it is safe on a hot
     * path where a permanent level would flood the log.
     */
    public static function debug( string $context, string $message ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        self::write( 'debug', $context, $message );
    }

    /**
     * @param string $level One of error|warning|debug.
     */
    private static function write( string $level, string $context, string $message ): void {
        $line = $context . ': ' . $message;

        // wc_get_logger() exists only once WooCommerce has loaded. Activation
        // and the autoloader run before that, and those are exactly the paths
        // whose failures matter most, so they must still reach somewhere.
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log( $level, $line, [ 'source' => self::SOURCE ] );

            return;
        }

        error_log( '[' . self::SOURCE . '] ' . strtoupper( $level ) . ' ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
