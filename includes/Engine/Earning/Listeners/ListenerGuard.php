<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/** Runs a listener handler so that nothing it throws reaches the WooCommerce or WordPress save that fired the hook. */
final class ListenerGuard {

    /**
     * A failed award or reversal is logged, never rethrown: an order, a
     * registration or a comment must save even when loyalty cannot.
     *
     * @param string $source  The listener, as the log line's prefix.
     * @param string $failure What failed, e.g. "award failed for order #12".
     */
    public static function run( string $source, string $failure, callable $handler ): void {
        try {
            $handler();
        } catch ( \Throwable $e ) {
            Log::error( $source, $failure . ': ' . $e->getMessage() );
        }
    }
}
