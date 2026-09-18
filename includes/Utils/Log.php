<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin logging, to WooCommerce → Status → Logs when it has loaded, else
 * error_log(). error() and warning() are always written; only debug() is
 * gated on WP_DEBUG, which production has off. $context is the reporting
 * class, used as the line's prefix.
 */
final class Log {

    /** WooCommerce log source and file name. */
    public const SOURCE = 'yayloyalty';

    /** A failure worth a permanent record. */
    public static function error( string $context, string $message ): void {
        self::write( 'error', $context, $message );
    }

    /** Something is wrong but the request continued. */
    public static function warning( string $context, string $message ): void {
        self::write( 'warning', $context, $message );
    }

    /** Diagnostic detail; silent unless WP_DEBUG, so safe on a hot path. */
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

        // Activation runs before WooCommerce loads, and its failures matter most.
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log( $level, $line, [ 'source' => self::SOURCE ] );

            return;
        }

        error_log( '[' . self::SOURCE . '] ' . strtoupper( $level ) . ' ' . $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
