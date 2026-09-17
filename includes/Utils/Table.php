<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Fully-qualified table names, one spelling for schemas and repositories.
 * Never memoize: multisite activation calls switch_to_blog(), and a cached
 * prefix would install every blog's tables into the first.
 */
final class Table {

    public const PREFIX = 'yayloyalty_';

    /**
     * @param string $slug Table slug without any prefix, e.g. 'customers'.
     */
    public static function name( string $slug ): string {
        global $wpdb;

        return $wpdb->prefix . self::PREFIX . $slug;
    }
}
