<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Shared accessors for a condition-type vocabulary, built from the using
 * class's schema() and `public const UNAVAILABLE`. The constant is a
 * contract, not enforced: PHP 7.4 traits cannot require or hold constants.
 */
trait ConditionTypeMethods {

    /**
     * Per-type rules, in evaluation-cost order.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function schema(): array;

    /**
     * Types a merchant can use today, in evaluation-cost order.
     *
     * @return string[]
     */
    public static function all(): array {
        return array_values( array_diff( array_keys( static::schema() ), static::UNAVAILABLE ) );
    }

    /**
     * Every type the schema recognises, gated ones included; for reading stored rows, never for offering choices.
     *
     * @return string[]
     */
    public static function all_known(): array {
        return array_keys( static::schema() );
    }

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, static::all(), true );
    }

    public static function is_known( $value ): bool {
        return is_string( $value ) && in_array( $value, static::all_known(), true );
    }

    /**
     * Operators a type accepts, or [] for an unknown type.
     *
     * @return string[]
     */
    public static function operators_for( string $type ): array {
        $operators = static::schema()[ $type ]['operators'] ?? [];

        return is_array( $operators ) ? $operators : [];
    }

    /**
     * @throws \InvalidArgumentException When $value is not a type a merchant can use.
     */
    public static function assert( $value ): string {
        if ( ! static::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown condition type "%s". Expected one of: %s.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', static::all() )
                )
            );
        }

        return $value;
    }
}
