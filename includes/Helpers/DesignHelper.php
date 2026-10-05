<?php
namespace YayLoyalty\Helpers;

use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Design storage: one option, cleaned by DesignSchema's rules on read and on write, like SettingsHelper.
 * A list replaces its default whole rather than merging by index, so a list the merchant cleared or
 * shortened stays that way. A stored '' storefront text is literal; reset() is the only route back to
 * the default wording.
 */
class DesignHelper {

    public const OPTION_DESIGN  = 'yayloyalty_design';
    public const SCHEMA_VERSION = 1;

    /** Stored design merged over defaults, sanitised for output, plus the derived launcher icon URL. */
    public static function get_design(): array {
        $saved  = self::load_stored();
        $merged = self::merge_over( DesignSchema::get_default_design(), self::sanitize_output( $saved ) );

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
        $base                       = self::merge_over( DesignSchema::get_default_design(), self::sanitize_output( self::load_stored() ) );
        $clean                      = self::validate( $design );
        $to_store                   = self::merge_over( $base, $clean );
        $to_store['schema_version'] = self::SCHEMA_VERSION;
        $dropped                    = self::dropped_keys( $design, $clean );

        if ( [] !== $dropped ) {
            // The route still answers 200, so without this line nothing records that a value was refused.
            Log::debug( 'DesignHelper', 'coerced to default, invalid input for: ' . implode( ', ', $dropped ) );
        }

        update_option( self::OPTION_DESIGN, $to_store, false );

        return self::get_design();
    }

    /**
     * The design as it would read once $design is saved, without saving it: the Design tab's live preview.
     * Same whitelist and coercion as update_design(), over the stored design.
     */
    public static function with_unsaved( array $design ): array {
        return self::merge_over( self::get_design(), self::validate( $design ) );
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

        $stored           = self::load_stored();
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

            foreach ( $fields as $field => $rule ) {
                if ( ! array_key_exists( $field, $data[ $group ] ) ) {
                    continue;
                }

                $value = DesignCoercion::coerce( $data[ $group ][ $field ], $rule );

                // null means "invalid" for every type coerce() returns; skip so the default survives the merge.
                if ( null !== $value ) {
                    $clean[ $group ][ $field ] = $value;
                }
            }
        }

        return $clean;
    }

    /** The stored option, or [] when it is missing or not an array. */
    private static function load_stored(): array {
        $stored = get_option( self::OPTION_DESIGN, [] );

        return is_array( $stored ) ? $stored : [];
    }

    /** $clean over $base: groups merge key by key, and a list replaces the base list whole instead of merging by index. */
    private static function merge_over( array $base, array $clean ): array {
        foreach ( $clean as $key => $value ) {
            $base[ $key ] = self::is_map( $value ) && self::is_map( $base[ $key ] ?? null )
                ? self::merge_over( $base[ $key ], $value )
                : $value;
        }

        return $base;
    }

    /** An array that is not a list, so [] counts as a list; PHP 7.4 has no array_is_list(). */
    private static function is_map( $value ): bool {
        return is_array( $value ) && array_values( $value ) !== $value;
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
