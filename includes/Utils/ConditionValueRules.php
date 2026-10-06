<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Value-shape rules shared by CampaignConditionSet and RewardConditionSet:
 * int, int_list, string_list and their helpers. Type vocabularies and
 * feature-specific shapes (money, country_list) stay on the owning classes.
 */
final class ConditionValueRules {

    /**
     * Applies a spec's normalize hints: array (widen a scalar; null → []), int, uppercase, lowercase, dedupe.
     *
     * @param string[] $hints
     */
    public static function normalize_scalar( array $hints, $value ) {
        if ( in_array( 'array', $hints, true ) && ! is_array( $value ) ) {
            $value = null === $value ? [] : [ $value ];
        }

        if ( ! is_array( $value ) ) {
            return $value;
        }

        if ( in_array( 'int', $hints, true ) ) {
            $value = array_map( 'intval', $value );
        }

        if ( in_array( 'uppercase', $hints, true ) ) {
            $value = array_map( 'strtoupper', array_map( 'strval', $value ) );
        }

        if ( in_array( 'lowercase', $hints, true ) ) {
            $value = array_map( 'strtolower', array_map( 'strval', $value ) );
        }

        if ( in_array( 'dedupe', $hints, true ) ) {
            $value = array_values( array_unique( $value ) );
        }

        return $value;
    }

    /** Validates a non-empty list of ids or strings, optionally capped. */
    public static function validate_list( string $type, $value, string $item_kind, ?int $max = null ): void {
        self::assert_non_empty_list( $type, $value );

        foreach ( $value as $item ) {
            if ( 'int' === $item_kind && ! self::is_int_like( $item ) ) {
                throw self::bad( $type, 'expects a list of integer ids' );
            }

            if ( 'string' === $item_kind && ( ! is_string( $item ) || '' === $item ) ) {
                throw self::bad( $type, 'expects a list of non-empty strings' );
            }
        }

        if ( null !== $max && count( $value ) > $max ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: "%s" accepts at most %d ids. For a larger segment, use a user_role condition instead.',
                    $type,
                    $max
                )
            );
        }
    }

    /** Validates an int value, or a [low, high] pair with `between`. $min rejects either bound below it. */
    public static function validate_int( string $type, string $operator, $value, ?int $min = null ): void {
        if ( 'between' === $operator ) {
            self::assert_between_pair( $type, $value, true );

            return;
        }

        if ( ! self::is_int_like( $value ) ) {
            throw self::bad( $type, 'expects an integer' );
        }

        if ( null !== $min && (float) $value < $min ) {
            throw self::bad( $type, sprintf( 'expects an integer of at least %d', $min ) );
        }
    }

    public static function assert_non_empty_list( string $type, $value ): void {
        if ( ! is_array( $value ) || [] === $value ) {
            throw self::bad( $type, 'value must be a non-empty array' );
        }
    }

    public static function assert_between_pair( string $type, $value, bool $integers ): void {
        if ( ! is_array( $value ) || 2 !== count( $value ) || ! array_key_exists( 0, $value ) || ! array_key_exists( 1, $value ) ) {
            throw self::bad( $type, '`between` takes a two-element [low, high] array' );
        }

        $low  = $value[0];
        $high = $value[1];

        $ok = $integers
            ? ( self::is_int_like( $low ) && self::is_int_like( $high ) )
            : ( ! is_bool( $low ) && is_numeric( $low ) && ! is_bool( $high ) && is_numeric( $high ) );

        if ( ! $ok ) {
            throw self::bad( $type, '`between` bounds must be numeric' );
        }

        if ( (float) $low > (float) $high ) {
            throw self::bad( $type, '`between` needs low <= high' );
        }
    }

    public static function is_int_like( $value ): bool {
        return ! is_bool( $value ) && is_numeric( $value ) && floor( (float) $value ) === (float) $value;
    }

    public static function bad( string $type, string $why ): \InvalidArgumentException {
        return new \InvalidArgumentException(
            sprintf( 'YayLoyalty: condition "%s" %s.', $type, $why )
        );
    }
}
