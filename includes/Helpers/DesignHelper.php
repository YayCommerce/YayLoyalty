<?php
namespace YayLoyalty\Helpers;

use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Design storage: one option, validated on read and write, mirroring SettingsHelper — with one deviation:
 * slug_list/badge_list fields are assigned wholesale after the recursive merge (array_replace_recursive
 * merges lists by index and would resurrect a default a merchant just cleared or shortened). A stored ''
 * storefront_text is literal, never re-substituted; reset() is the only route back to the default wording.
 * Field declarations live in DesignSchema, per-type sanitisation in DesignCoercion; this class is the
 * persistence/merge orchestrator — split three ways because the field surface (six groups, ~30 fields, two
 * closed icon vocabularies) made one file unreadable at the project's file-size budget.
 */
class DesignHelper {

    public const OPTION_DESIGN  = 'yayloyalty_design';
    public const SCHEMA_VERSION = 1;

    /** Stored design merged over defaults, sanitised for output, plus the derived launcher icon URL. */
    public static function get_design(): array {
        $saved  = get_option( self::OPTION_DESIGN, [] );
        $merged = self::merge_over( DesignSchema::get_default_design(), self::sanitize_output( is_array( $saved ) ? $saved : [] ) );

        $merged['schema_version'] = isset( $saved['schema_version'] ) ? (int) $saved['schema_version'] : self::SCHEMA_VERSION;

        $upload_id  = (int) $merged['launcher']['icon_upload_id'];
        $upload_url = $upload_id > 0 ? wp_get_attachment_url( $upload_id ) : '';
        // Derived, never stored: an attachment id removes the arbitrary-origin risk a raw URL field would carry.
        $merged['launcher']['icon_upload_url'] = $upload_url ? (string) $upload_url : '';

        return $merged;
    }

    /**
     * Validates, merges over the *currently stored* design (not the bare defaults) so a field coerce()
     * dropped — most notably a wholly-invalid badge_list, which validate() leaves out of $clean on purpose
     * — keeps its last-saved value instead of reverting to the factory default. Stamps the version, saves,
     * logs what was dropped.
     */
    public static function update_design( array $design ): array {
        $current                    = get_option( self::OPTION_DESIGN, [] );
        $current                    = self::sanitize_output( is_array( $current ) ? $current : [] );
        $base                       = self::merge_over( DesignSchema::get_default_design(), $current );
        $clean                      = self::validate( $design );
        $to_store                   = self::merge_over( $base, $clean );
        $to_store['schema_version'] = self::SCHEMA_VERSION;
        $dropped                    = self::dropped_keys( $design, $clean );

        if ( [] !== $dropped ) {
            // Otherwise a merchant gets a 200 and believes an invalid value saved; see Requirements.
            Log::debug( 'DesignHelper', 'coerced to default, invalid input for: ' . implode( ', ', $dropped ) );
        }

        update_option( self::OPTION_DESIGN, $to_store, false );

        return self::get_design();
    }

    /** Restores one group, or the whole blob when $group is null. Logs at warning: destructive, irreversible, must survive production. */
    public static function reset( ?string $group = null ): array {
        $user_id = get_current_user_id();

        if ( null === $group ) {
            delete_option( self::OPTION_DESIGN );
            Log::warning( 'DesignHelper', "design reset (all groups) by user #{$user_id}" );
            return self::get_design();
        }

        if ( ! array_key_exists( $group, DesignSchema::schema() ) ) {
            return self::get_design();
        }

        $stored           = get_option( self::OPTION_DESIGN, [] );
        $stored           = is_array( $stored ) ? $stored : [];
        $stored[ $group ] = DesignSchema::get_default_design()[ $group ];

        update_option( self::OPTION_DESIGN, $stored, false );
        Log::warning( 'DesignHelper', "design group \"{$group}\" reset by user #{$user_id}" );

        return self::get_design();
    }

    /** Whitelists and coerces incoming design fields. */
    public static function validate( array $input ): array {
        return self::apply_schema( $input );
    }

    /** Whitelists and coerces stored design on the way out. Same rules as validate(); read path is not forgotten. */
    public static function sanitize_output( array $stored ): array {
        return self::apply_schema( $stored );
    }

    private static function apply_schema( array $data ): array {
        $clean = [];

        foreach ( DesignSchema::schema() as $group => $fields ) {
            if ( ! isset( $data[ $group ] ) || ! is_array( $data[ $group ] ) ) {
                continue;
            }

            foreach ( $fields as $field => $type ) {
                if ( ! array_key_exists( $field, $data[ $group ] ) ) {
                    continue;
                }

                $value = DesignCoercion::coerce( $data[ $group ][ $field ], $type, $group, $field );

                // null means "invalid" for every type coerce() returns; skip so the default survives the merge.
                if ( null !== $value ) {
                    $clean[ $group ][ $field ] = $value;
                }
            }
        }

        return $clean;
    }

    /** Wholesale-assigns present slug_list/badge_list fields — array_replace_recursive merges lists by index; see the class docblock. */
    private static function merge_over( array $base, array $clean ): array {
        $merged = array_replace_recursive( $base, $clean );

        foreach ( DesignSchema::schema() as $group => $fields ) {
            foreach ( $fields as $field => $type ) {
                if ( ( 'slug_list' === $type || 'badge_list' === $type ) && isset( $clean[ $group ][ $field ] ) ) {
                    $merged[ $group ][ $field ] = $clean[ $group ][ $field ];
                }
            }
        }

        return $merged;
    }

    /**
     * Dot-path fields present in $input but absent from $clean: coerce() rejected or dropped them.
     *
     * @return string[]
     */
    private static function dropped_keys( array $input, array $clean ): array {
        $dropped = [];

        foreach ( DesignSchema::schema() as $group => $fields ) {
            if ( ! isset( $input[ $group ] ) || ! is_array( $input[ $group ] ) ) {
                continue;
            }

            foreach ( array_keys( $fields ) as $field ) {
                if ( array_key_exists( $field, $input[ $group ] ) && ! isset( $clean[ $group ][ $field ] ) ) {
                    $dropped[] = "{$group}.{$field}";
                }
            }
        }

        return $dropped;
    }
}
