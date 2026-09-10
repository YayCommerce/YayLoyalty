<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Fully-qualified table names.
 *
 * One place decides what this plugin's tables are called. The schema classes
 * create them and the repositories read them, and if those two ever spelled the
 * prefix differently the repository would query a table the installer never
 * made — a failure that shows up as "no rows", not as an error.
 *
 * NEVER MEMOIZE THE RESULT, and never cache $wpdb->prefix in a property or a
 * static. Multisite activation loops switch_to_blog(), which repoints the
 * prefix mid-request; a cached one installs every blog's tables into the first
 * blog. This class reads it fresh on every call for that reason, and it is
 * cheap enough that there is no argument for doing otherwise.
 */
final class Table {

    /** Distinguishes this plugin's tables from every other plugin's. */
    public const PREFIX = 'yayloyalty_';

    /**
     * @param string $slug Table slug without any prefix, e.g. 'customers'.
     */
    public static function name( string $slug ): string {
        global $wpdb;

        return $wpdb->prefix . self::PREFIX . $slug;
    }
}
