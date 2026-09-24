<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans one design value by its DesignSchema rule. Knows types, never field names: every limit comes
 * from the rule. Returns null when the value is invalid, and the caller then keeps the prior value.
 */
class DesignCoercion {

    private const DEFAULT_BADGE_COLOR = '#c8c8c8';

    /**
     * Returns $value cleaned by $rule, or null when it is invalid.
     *
     * Types, with the limit keys each reads:
     * choice (options), bool, int (min, max), color, attachment_id, id_list, slug_list, badge_list,
     * text (max = 200, required = false), textarea (max = 20000).
     * List types must return a re-indexed list: DesignHelper replaces a list whole but merges a keyed array.
     *
     * @param mixed                $value
     * @param array<string, mixed> $rule DesignSchema::schema() entry: its type plus any limits.
     * @return mixed
     */
    public static function coerce( $value, array $rule ) {
        switch ( $rule['type'] ) {
            case 'choice':
                $choice = sanitize_key( (string) $value );
                return in_array( $choice, $rule['options'], true ) ? $choice : null;
            case 'bool':
                return rest_sanitize_boolean( $value ); // a plain cast would store the string "false" as true
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
            case 'badge_list':
                return self::badge_list( $value );
            case 'textarea':
                return mb_substr( sanitize_textarea_field( (string) $value ), 0, $rule['max'] ?? 20000 );
            case 'text':
            default:
                return self::text( (string) $value, $rule['max'] ?? 200, $rule['required'] ?? false );
        }
    }

    /** A required text refuses blank on the server too; the SPA's non-empty check is only a convenience. */
    private static function text( string $value, int $max, bool $required ): ?string {
        $text = sanitize_text_field( $value );

        if ( $required && '' === trim( $text ) ) {
            return null;
        }

        return mb_substr( $text, 0, $max );
    }

    /**
     * [] is a merchant deleting every badge and is stored; null means every entry was malformed and is not a
     * wipe — DesignHelper then omits tier.badges from the clean array, so the stored value survives.
     * Duplicate tier_id (the only thing a future Tiers migration can match on) is deduped, first kept.
     */
    private static function badge_list( $value ): ?array {
        if ( ! is_array( $value ) ) {
            return null;
        }
        if ( [] === $value ) {
            return [];
        }

        $seen = [];
        $out  = [];

        foreach ( $value as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $tier_id = sanitize_key( $entry['tier_id'] ?? '' );

            if ( '' === $tier_id || isset( $seen[ $tier_id ] ) ) {
                continue;
            }

            $seen[ $tier_id ] = true;
            $color            = sanitize_hex_color( (string) ( $entry['color'] ?? '' ) );
            $icon             = sanitize_key( (string) ( $entry['icon'] ?? '' ) );

            $out[] = [
                'tier_id' => mb_substr( $tier_id, 0, 60 ),
                'color'   => $color ? $color : self::DEFAULT_BADGE_COLOR,
                'icon'    => in_array( $icon, DesignSchema::BADGE_ICONS, true ) ? $icon : 'ring',
                'label'   => mb_substr( sanitize_text_field( (string) ( $entry['label'] ?? '' ) ), 0, 60 ),
            ];

            if ( 20 === count( $out ) ) {
                break;
            }
        }

        return [] === $out ? null : $out;
    }
}
