<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Support;

defined('ABSPATH') || exit;
/**
 * Slug utilities.
 *
 * Plugin slugs can contain characters that are valid in wp_options keys
 * and URL paths but invalid as JavaScript identifiers (e.g. hyphens, '&').
 * This helper sanitizes a slug for use as a JS variable name while the
 * original slug stays intact for option-key and REST-URL contexts.
 */
class Slug
{
    /**
     * Convert a plugin slug into a valid JS identifier.
     *
     * Rules:
     *  - Replace any char outside [A-Za-z0-9_] with '_'
     *  - Collapse consecutive underscores
     *  - Trim leading/trailing underscores
     *  - Prefix '_' if the result starts with a digit
     *  - Return '_' for empty / all-special input (never empty string)
     *
     * Idempotent: to_var_name( to_var_name( $x ) ) === to_var_name( $x ).
     */
    public static function to_var_name(string $slug): string
    {
        $name = preg_replace('/[^A-Za-z0-9_]/', '_', $slug);
        $name = preg_replace('/_+/', '_', (string) $name);
        $name = trim((string) $name, '_');
        if ('' === $name) {
            return '_';
        }
        if (preg_match('/^\d/', $name)) {
            $name = '_' . $name;
        }
        return $name;
    }
}
