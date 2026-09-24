<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Per-type sanitisation for DesignHelper::apply_schema() — one branch per DesignSchema::schema() type value,
 * plus the badge_list special case. Split out of DesignHelper so the coercion rules (length caps, the
 * structural-label non-empty rule, badge dedup) read as one focused unit; DesignSchema owns the field
 * declarations this dispatches against, DesignHelper owns persistence and the merge.
 */
class DesignCoercion {

    private const STRUCTURAL_LABELS   = [ 'launcher_label', 'balance_heading', 'redeem_button' ];
    private const DEFAULT_BADGE_COLOR = '#c8c8c8';

    /**
     * @param mixed           $value
     * @param string|string[] $type Schema type, or a closed choice list.
     * @return mixed
     */
    public static function coerce( $value, $type, string $group, string $field ) {
        if ( is_array( $type ) ) {
            $choice = sanitize_key( (string) $value );
            return in_array( $choice, $type, true ) ? $choice : null;
        }

        switch ( $type ) {
            case 'bool':
                return (bool) $value;
            case 'radius':
                return min( 24, max( 0, (int) $value ) );
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
                return self::coerce_badge_list( $value );
            case 'textarea':
                return mb_substr( sanitize_textarea_field( (string) $value ), 0, 20000 ); // only custom_css uses this type
            case 'text':
            default:
                return self::coerce_text( (string) $value, $group, $field );
        }
    }

    /** Structural labels reject blank server-side — the SPA's zod .min(1) is a usability nicety on top, not the only guard. */
    private static function coerce_text( string $value, string $group, string $field ) {
        $text = sanitize_text_field( $value );

        if ( 'storefront_text' === $group && '' === trim( $text ) && in_array( $field, self::STRUCTURAL_LABELS, true ) ) {
            return null;
        }

        $cap = 'typography' === $group && 'font' === $field ? 120 : 200; // font is the one 'text' field with a narrower cap

        return mb_substr( $text, 0, $cap );
    }

    /**
     * [] is a merchant deleting every badge and is stored; null means every entry was malformed and is not a
     * wipe — apply_schema() then omits tier.badges from the clean array, so the stored value survives.
     * Duplicate tier_id (the only thing a future Tiers migration can match on) is deduped, first kept.
     */
    private static function coerce_badge_list( $value ): ?array {
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
