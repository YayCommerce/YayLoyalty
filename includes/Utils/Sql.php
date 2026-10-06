<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/** Stateless SQL fragment helpers with no $wpdb dependency. */
final class Sql {

    /** Placeholders for a prepared `IN (...)` clause, one per value, so the bound list always matches the built one. */
    public static function in_placeholders( array $values, string $placeholder = '%s' ): string {
        return implode( ', ', array_fill( 0, count( $values ), $placeholder ) );
    }
}
