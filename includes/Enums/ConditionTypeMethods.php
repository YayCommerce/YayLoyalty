<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Shared processing for a condition-type vocabulary — `all()`, `all_known()`,
 * `is_valid()`, `is_known()`, `operators_for()`, `assert()` — built entirely
 * from a using class's own `schema()` and `UNAVAILABLE`.
 *
 * CONTRACT for a class using this trait:
 *   - implement `public static function schema(): array` — enforced below,
 *     declared `abstract`.
 *   - declare `public const UNAVAILABLE` — a list of types excluded from
 *     all() but still recognised by all_known(). PHP 7.4 cannot enforce a
 *     trait-required constant the way it enforces a required method (trait
 *     constants only arrived in PHP 8.2), so this is a contract, not a
 *     compile-time check. Every current user declares it, even if `[]`.
 *
 * Operator constants (OP_IN, OP_GTE, …) are deliberately NOT here: a trait
 * cannot hold constants before PHP 8.2 either, and they are inert literal
 * strings with no logic to drift — the duplication this trait exists to
 * remove is in the methods below, not in eight string literals.
 */
trait ConditionTypeMethods {

    /**
     * Per-type rules, in evaluation-cost order. This is the one method every
     * user of this trait must supply — see each class for its own vocabulary.
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
     * Every type the schema recognises, gated types included. For reading
     * stored rows, never for offering choices.
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
