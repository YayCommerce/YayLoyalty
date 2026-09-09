<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * JSON columns.
 *
 * WooCommerce's minimum MySQL/MariaDB versions do not guarantee a native JSON
 * type, so JSON lives in LONGTEXT and is validated in PHP. Every blob carries a
 * schema_version key so formats can evolve without a table migration.
 *
 * Decoding is TOTAL: malformed stored data returns the default and logs. It
 * never throws. A corrupt config blob should degrade one campaign, not
 * white-screen the admin.
 */
final class Json {

    public const SCHEMA_VERSION_KEY = 'schema_version';

    /**
     * Encode an array for storage, stamping the schema version.
     *
     * @param array $data    Payload. Any existing schema_version is overwritten.
     * @param int   $version Schema version to stamp.
     */
    public static function encode( array $data, int $version ): string {
        $data[ self::SCHEMA_VERSION_KEY ] = $version;

        $encoded = wp_json_encode( $data );

        if ( false === $encoded ) {
            self::log( 'encode failed: ' . json_last_error_msg() );

            return wp_json_encode( [ self::SCHEMA_VERSION_KEY => $version ] );
        }

        return $encoded;
    }

    /**
     * Decode a stored blob. Never throws.
     *
     * @param string|null $raw     Stored column value.
     * @param array       $fallback Returned when $raw is empty or malformed.
     */
    public static function decode( ?string $raw, array $fallback = [] ): array {
        if ( null === $raw || '' === trim( (string) $raw ) ) {
            return $fallback;
        }

        $decoded = json_decode( $raw, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            self::log( 'decode failed: ' . json_last_error_msg() );

            return $fallback;
        }

        return $decoded;
    }

    /*
     * Deliberately absent: version_of().
     *
     * Nothing reads a schema version until a second version exists, so it would
     * ship with zero call sites. Add it with the first migration that actually
     * branches on the version. A blob with no schema_version key is version 0
     * and gets stamped on its next save, so no migration is needed to backfill.
     */

    private static function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[yayloyalty] Json: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
