<?php
namespace YayLoyalty\Models;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * A typed row: holds data and casts it, never touches the database or a
 * cross-table invariant. Money (DECIMAL) is carried as a string and bound
 * as %s, never %f: a float loses precision exactly where it is a financial
 * defect. Append-only entities are immutable by convention (getters only,
 * with_*() returns a clone). PHP 7.4 has no readonly to enforce it.
 */
abstract class AbstractModel {

    /**
     * @param array<string, mixed> $row
     * @return static
     */
    abstract public static function from_row( array $row );

    /**
     * Column => value map for $wpdb writes. It omits an AUTO_INCREMENT key and keeps a natural one.
     *
     * @return array<string, mixed>
     */
    abstract public function to_row(): array;

    /**
     * REST representation, snake_case, matching the owning controller's schema.
     *
     * @return array<string, mixed>
     */
    abstract public function to_array(): array;

    /**
     * $wpdb placeholder per to_row() key, in the same order. Decimals take '%s', never '%f'.
     *
     * @return string[]
     */
    abstract public function get_format(): array;

    /** Null and '' become null. */
    protected static function cast_int( $value ): ?int {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return (int) $value;
    }

    /** For NOT NULL integer columns. */
    protected static function cast_int_default( $value, int $fallback = 0 ): int {
        if ( null === $value || '' === $value ) {
            return $fallback;
        }

        return (int) $value;
    }

    /** Casts a DECIMAL column to a string, never a float, so %s binding downstream keeps precision. */
    protected static function cast_decimal( $value, ?string $fallback = null ): ?string {
        if ( null === $value || '' === $value ) {
            return $fallback;
        }

        return (string) $value;
    }

    /** Empty and zero dates become null. */
    protected static function cast_datetime( $value ): ?string {
        if ( null === $value || '' === $value || Dates::ZERO === $value ) {
            return null;
        }

        return (string) $value;
    }

    protected static function cast_string( $value ): ?string {
        if ( null === $value ) {
            return null;
        }

        return (string) $value;
    }

    protected static function cast_bool( $value ): bool {
        return (bool) (int) $value;
    }
}
