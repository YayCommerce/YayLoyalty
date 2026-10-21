<?php
namespace YayLoyalty\Helpers;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Design storage: one option, cleaned by DesignSchema's rules on read and on write, like SettingsHelper.
 * A list replaces its default whole rather than merging by index, so a list the merchant cleared or
 * shortened stays that way. reset() is the only route back to the defaults.
 */
class DesignHelper {

    public const OPTION_DESIGN  = 'yayloyalty_design';
    public const SCHEMA_VERSION = 1;

    /** Stored design merged over defaults, sanitised for output. */
    public static function get_design(): array {
        $saved  = self::load_stored();
        $merged = self::merge_over( DesignSchema::get_default_design(), self::sanitize_output( $saved ) );

        $merged['schema_version'] = isset( $saved['schema_version'] ) ? (int) $saved['schema_version'] : self::SCHEMA_VERSION;

        return $merged;
    }

    /** Whether a storefront surface shows: the program is on and its Design → Visibility switch, such as `product_message`, is on. */
    public static function surface_enabled( array $design, string $flag ): bool {
        return ProgramGate::program_enabled() && ! empty( $design['visibility'][ $flag ] );
    }

    /**
     * Validates $design and merges it over the stored design, not the bare defaults. A field coerce() dropped
     * then keeps its last-saved value instead of reverting to the default.
     * Stamps the version, saves, and logs what was dropped.
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

    /** Restores the whole design to its defaults. Logs at warning, which production keeps: a reset is irreversible. */
    public static function reset(): array {
        delete_option( self::OPTION_DESIGN );
        Log::warning( 'DesignHelper', 'design reset by user #' . get_current_user_id() );

        return self::get_design();
    }

    /** Whitelists and coerces incoming design fields. */
    public static function validate( array $input ): array {
        return self::apply_schema( $input );
    }

    /** Whitelists and coerces the stored design on the way out, with validate()'s rules. */
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

                // coerce() returns null only for an invalid value. Skipping it lets the base value survive the merge.
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

    /** An array that is not a list, so [] counts as a list. PHP 7.4 has no array_is_list(). */
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
