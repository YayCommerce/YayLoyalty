<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Shared is_valid() and assert() for a string vocabulary, built from the using class's all() and noun().
 */
trait StringEnumMethods {

    /** @return string[] */
    abstract public static function all(): array;

    /** What a value names, as in "ledger type", for the error message. */
    abstract protected static function noun(): string;

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, static::all(), true );
    }

    /** @throws \InvalidArgumentException When $value is not in all(). */
    public static function assert( $value ): string {
        if ( ! static::is_valid( $value ) ) {
            throw static::unknown_value( $value );
        }

        return $value;
    }

    /** A sentence appended to the assert() message, with its leading space. */
    protected static function assert_note(): string {
        return '';
    }

    protected static function unknown_value( $value, string $expected = 'one of' ): \InvalidArgumentException {
        return new \InvalidArgumentException(
            sprintf(
                'YayLoyalty: unknown %s "%s". Expected %s: %s.%s',
                static::noun(),
                is_scalar( $value ) ? (string) $value : gettype( $value ),
                $expected,
                implode( ', ', static::all() ),
                static::assert_note()
            )
        );
    }
}
