<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * JSON columns, stored as LONGTEXT and validated in PHP: WooCommerce's minimum MySQL has no native JSON.
 * Every blob is stamped with schema_version. Decoding never throws, so a corrupt blob degrades one row,
 * not the whole admin.
 */
final class Json {

    public const SCHEMA_VERSION_KEY = 'schema_version';

    /** Envelope key holding a list payload; see encode_list(). */
    public const ITEMS_KEY = 'items';

    /**
     * Encodes a map, stamping the schema version. Lists use encode_list():
     * stamping a string key into a list turns it into an object.
     *
     * @param array $data Payload. Any existing schema_version is overwritten.
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
     * Decodes a stored blob. Never throws.
     *
     * @param array $fallback Returned when $raw is empty or malformed.
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

    /**
     * Encodes a list in a {"schema_version", "items"} envelope, since a list
     * cannot carry a version key and stay a list. An empty list decodes back to an empty list.
     */
    public static function encode_list( array $items, int $version ): string {
        return self::encode(
            [ self::ITEMS_KEY => array_values( $items ) ],
            $version
        );
    }

    /**
     * Decodes a list written by encode_list(). Never throws. Also accepts a
     * bare array and the `{"0":…,"schema_version":1}` shape an earlier
     * encode() produced, so no stored row is lost.
     */
    public static function decode_list( ?string $raw, array $fallback = [] ): array {
        $decoded = self::decode( $raw, $fallback );

        if ( isset( $decoded[ self::ITEMS_KEY ] ) && is_array( $decoded[ self::ITEMS_KEY ] ) ) {
            return array_values( $decoded[ self::ITEMS_KEY ] );
        }

        unset( $decoded[ self::SCHEMA_VERSION_KEY ] );

        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) {
                return $fallback;
            }
        }

        return array_values( $decoded );
    }

    /** Debug level: decoding runs per row, so a bad column would otherwise flood the log. */
    private static function log( string $message ): void {
        Log::debug( 'Json', $message );
    }
}
