<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/** Stateless SQL fragment helpers with no $wpdb dependency. */
final class Sql {

    /**
     * A fixed-count placeholder list for a prepared `IN (...)` clause — one
     * placeholder per value, so the list a caller binds always matches the
     * list it built.
     *
     * @param mixed[] $values
     */
    public static function in_placeholders( array $values, string $placeholder = '%s' ): string {
        return implode( ', ', array_fill( 0, count( $values ), $placeholder ) );
    }
}
