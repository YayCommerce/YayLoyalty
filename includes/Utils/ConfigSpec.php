<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a config value against a typed spec, for the campaign and reward config schemas.
 * Spec types: bool, string [max_length], enum [values], enum_list [values], currency, int and number [min, exclusive_min, max],
 * nullable_int, nullable_number, int_list, and limit (null = no limit). An unknown type is accepted.
 * A string's max_length counts characters after sanitize_text_field(), what the storefront prints of it.
 */
final class ConfigSpec {

    /** @param array<string, mixed> $spec */
    public static function matches( $value, array $spec ): bool {
        switch ( $spec['type'] ?? '' ) {
            case 'bool':
                return is_bool( $value );

            case 'string':
                return is_string( $value ) && ( ! isset( $spec['max_length'] ) || self::text_length( $value ) <= (int) $spec['max_length'] );

            case 'enum':
                return is_string( $value ) && in_array( $value, (array) ( $spec['values'] ?? [] ), true );

            case 'enum_list':
                if ( ! is_array( $value ) ) {
                    return false;
                }

                foreach ( $value as $item ) {
                    if ( ! is_string( $item ) || ! in_array( $item, (array) ( $spec['values'] ?? [] ), true ) ) {
                        return false;
                    }
                }

                return true;

            case 'currency':
                return is_string( $value ) && IsoCodes::is_currency( $value );

            case 'int':
                return ConditionValueRules::is_int_like( $value ) && self::within_bounds( (float) $value, $spec );

            case 'number':
                return ! is_bool( $value ) && is_numeric( $value ) && self::within_bounds( (float) $value, $spec );

            case 'nullable_int':
                return null === $value || self::matches( $value, [ 'type' => 'int' ] + $spec );

            case 'nullable_number':
                return null === $value || self::matches( $value, [ 'type' => 'number' ] + $spec );

            // A cap of 0 would block everything, so a set limit starts at 1.
            case 'limit':
                return null === $value || self::is_limit( $value );

            case 'int_list':
                if ( ! is_array( $value ) ) {
                    return false;
                }

                foreach ( $value as $item ) {
                    if ( ! ConditionValueRules::is_int_like( $item ) ) {
                        return false;
                    }
                }

                return true;

            default:
                return true;
        }
    }

    /** Characters left after sanitize_text_field(); trim() under plain PHPUnit, where WordPress is not loaded. */
    private static function text_length( string $value ): int {
        $text = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );

        return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
    }

    /** A set limit: a whole number from 1. Null, for no limit, is the caller's check. */
    public static function is_limit( $value ): bool {
        return ConditionValueRules::is_int_like( $value ) && (float) $value >= 1;
    }

    /** @param array<string, mixed> $spec */
    public static function within_bounds( float $value, array $spec ): bool {
        if ( isset( $spec['exclusive_min'] ) && $value <= (float) $spec['exclusive_min'] ) {
            return false;
        }

        if ( isset( $spec['min'] ) && $value < (float) $spec['min'] ) {
            return false;
        }

        return ! isset( $spec['max'] ) || $value <= (float) $spec['max'];
    }
}
