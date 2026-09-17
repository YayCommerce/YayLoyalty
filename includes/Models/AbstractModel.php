<?php
namespace YayLoyalty\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A typed row. Dumb by design.
 *
 * Models hold data and cast it. They never touch the database — that is a
 * repository's job — and they never enforce cross-table invariants, which is a
 * service's job.
 *
 * Two rules are easy to get wrong and expensive to get wrong:
 *
 * 1. MONEY BINDS AS %s, NEVER %f. Every DECIMAL(19,4) column MUST be carried
 *    as a PHP string and declared as '%s' in get_format(), even though none is
 *    mapped by a model yet — the first will be customers.lifetime_spend.
 *    Passing a decimal through a float loses precision on exactly the values
 *    where it is a financial defect. Points are BIGINT and use '%d'.
 *
 * 2. FACT-TABLE ENTITIES ARE IMMUTABLE BY CONVENTION. PHP 7.4 has no readonly,
 *    so subclasses representing append-only rows (today only LedgerEntry; a
 *    future Redemption model follows the same rules) declare private
 *    properties, expose getters only, and return a clone from any with_*()
 *    method. The convention is the enforcement; there is no language support
 *    to fall back on.
 */
abstract class AbstractModel {

    /**
     * Build from a raw database row.
     *
     * @param array<string, mixed> $row
     * @return static
     */
    abstract public static function from_row( array $row );

    /**
     * Column => value map for $wpdb write methods.
     *
     * Omit an AUTO_INCREMENT primary key on insert; a natural key such as
     * customers.user_id stays. The caller supplies the AUTO_INCREMENT key for
     * updates.
     *
     * @return array<string, mixed>
     */
    abstract public function to_row(): array;

    /**
     * Public REST representation. snake_case keys, matching the owning
     * controller's schema where one exists (rewards have no REST surface yet).
     *
     * @return array<string, mixed>
     */
    abstract public function to_array(): array;

    /**
     * $wpdb placeholder for each key of to_row(), in the same order.
     *
     * '%d' for integers, '%s' for strings, dates AND decimals. Never '%f'.
     *
     * @return string[]
     */
    abstract public function get_format(): array;

    /**
     * Cast a value to int, treating null and '' as null.
     */
    protected static function cast_int( $value ): ?int {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Cast a value to int, defaulting to 0. For NOT NULL integer columns.
     */
    protected static function cast_int_default( $value, int $fallback = 0 ): int {
        if ( null === $value || '' === $value ) {
            return $fallback;
        }

        return (int) $value;
    }

    /**
     * Cast a DECIMAL column.
     *
     * Returns a STRING, deliberately. Casting to float here would defeat the
     * whole point of binding with %s downstream.
     */
    protected static function cast_decimal( $value, ?string $fallback = null ): ?string {
        if ( null === $value || '' === $value ) {
            return $fallback;
        }

        return (string) $value;
    }

    /**
     * Cast a nullable DATETIME column, normalising empty and zero dates to null.
     */
    protected static function cast_datetime( $value ): ?string {
        if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Cast a nullable string column.
     */
    protected static function cast_string( $value ): ?string {
        if ( null === $value ) {
            return null;
        }

        return (string) $value;
    }

    /**
     * Cast a TINYINT(1) column to bool.
     */
    protected static function cast_bool( $value ): bool {
        return (bool) (int) $value;
    }
}
