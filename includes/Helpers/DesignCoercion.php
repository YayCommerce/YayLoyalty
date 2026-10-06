<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans one design value by its DesignSchema rule. Knows types, never field names: every limit comes
 * from the rule. Returns null when the value is invalid, and the caller then keeps the prior value.
 */
class DesignCoercion {

    /**
     * Returns $value cleaned by $rule, or null when it is invalid.
     *
     * Types, with the limit keys each reads:
     * choice (options), bool, int (min, max), color, attachment_id, id_list, slug_list,
     * text (max = 200, required = false), textarea (max = 20000).
     * List types must return a re-indexed list: DesignHelper replaces a list whole but merges a keyed array.
     *
     * @param array<string, mixed> $rule DesignSchema::schema() entry: its type plus any limits.
     */
    public static function coerce( $value, array $rule ) {
        switch ( $rule['type'] ) {
            case 'choice':
                $choice = sanitize_key( (string) $value );
                return in_array( $choice, $rule['options'], true ) ? $choice : null;
            case 'bool':
                return rest_sanitize_boolean( $value ); // A plain cast would store the string "false" as true.
            case 'int':
                return min( $rule['max'], max( $rule['min'], (int) $value ) );
            case 'color':
                $color = sanitize_hex_color( (string) $value );
                return $color ? $color : null;
            case 'attachment_id':
                return absint( $value );
            case 'id_list':
                return array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) ), 0, 100 );
            case 'slug_list':
                return array_slice( array_values( array_filter( array_map( 'sanitize_key', (array) $value ) ) ), 0, 100 );
            case 'textarea':
                return mb_substr( sanitize_textarea_field( (string) $value ), 0, $rule['max'] ?? 20000 );
            case 'text':
            default:
                return self::text( (string) $value, $rule['max'] ?? 200, $rule['required'] ?? false );
        }
    }

    /** A required text refuses blank on the server too. The SPA's non-empty check is only a convenience. */
    private static function text( string $value, int $max, bool $required ): ?string {
        $text = sanitize_text_field( $value );

        if ( $required && '' === trim( $text ) ) {
            return null;
        }

        return mb_substr( $text, 0, $max );
    }
}
