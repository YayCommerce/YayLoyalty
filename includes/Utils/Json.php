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

    /** Envelope key holding a list payload. @see encode_list() */
    public const ITEMS_KEY = 'items';

    /**
     * Encode a MAP for storage, stamping the schema version.
     *
     * For list-shaped payloads use encode_list(). Stamping a string key into a
     * list turns it into an object: `[{…},{…}]` would come back as
     * `{"0":…,"1":…,"schema_version":1}`, and anything iterating it would find
     * a stray integer where it expected a record.
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

    /**
     * Encode a LIST for storage, wrapped so the version has somewhere to live.
     *
     * `campaigns.conditions` is a list of {type, operator, value} triples, and
     * a list cannot carry a version key without ceasing to be a list. The
     * envelope keeps the payload a genuine array:
     *
     *   {"schema_version": 1, "items": [ {...}, {...} ]}
     *
     * An empty list stays an empty list on the way back, which matters: code
     * asking "does this campaign have conditions?" must get a straight answer,
     * not an array containing only bookkeeping.
     *
     * @param array $items
     */
    public static function encode_list( array $items, int $version ): string {
        return self::encode(
            [ self::ITEMS_KEY => array_values( $items ) ],
            $version
        );
    }

    /**
     * Decode a list written by encode_list(). Never throws.
     *
     * Also accepts two older shapes so no stored row is lost: a bare JSON
     * array, and the corrupted `{"0":…,"schema_version":1}` an earlier
     * encode() produced when handed a list.
     *
     * @param string|null $raw
     * @param array       $fallback
     */
    public static function decode_list( ?string $raw, array $fallback = [] ): array {
        $decoded = self::decode( $raw, $fallback );

        if ( isset( $decoded[ self::ITEMS_KEY ] ) && is_array( $decoded[ self::ITEMS_KEY ] ) ) {
            return array_values( $decoded[ self::ITEMS_KEY ] );
        }

        unset( $decoded[ self::SCHEMA_VERSION_KEY ] );

        // What remains is either a bare list or a salvaged one; anything whose
        // entries are not records is not a condition list.
        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) {
                return $fallback;
            }
        }

        return array_values( $decoded );
    }

    /*
     * Deliberately absent: version_of().
     *
     * Nothing reads a schema version until a second version exists, so it would
     * ship with zero call sites. Add it with the first migration that actually
     * branches on the version. A blob with no schema_version key is version 0
     * and gets stamped on its next save, so no migration is needed to backfill.
     */

    /**
     * Debug level on purpose: one malformed blob degrades one row, and decoding
     * happens per row, so a permanent level would flood the log on a table that
     * has a bad column.
     */
    private static function log( string $message ): void {
        Log::debug( 'Json', $message );
    }
}
