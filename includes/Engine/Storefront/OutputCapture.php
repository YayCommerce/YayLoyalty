<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Buffers a storefront template, so one that fails costs only itself, never the page.
 */
final class OutputCapture {

    /**
     * What $output printed, or null when it threw (logged under $context).
     *
     * @param callable $output  Prints the template.
     * @param string   $context The reporting class, for the log.
     */
    public static function capture( callable $output, string $context ): ?string {
        $buffer_level = ob_get_level();

        try {
            ob_start();
            $output();

            return (string) ob_get_clean();
        } catch ( \Throwable $e ) {
            // Drop the half-printed template, never the theme's own buffers.
            while ( ob_get_level() > $buffer_level ) {
                ob_end_clean();
            }

            Log::warning( $context, 'not rendered: ' . $e->getMessage() );

            return null;
        }
    }
}
