<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The structure of a campaign's or a reward's `conditions`: one list joined by `all` (AND) or `any` (OR).
 * A flat list, as 1.0.0 stored and as any API caller may still send, reads as `all`.
 *
 * Shape: { match: all|any, conditions: [ {type, operator, value} ] }.
 */
final class ConditionMatch {

    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    /** The column's schema_version for an `any` list. An `all` list keeps the 1.0.0 envelope. */
    public const ANY_SCHEMA_VERSION = 2;

    private const MATCH_KEY = 'match';

    /**
     * Either shape to { match, conditions }. Lenient: a match value passes through for assert_structure()
     * to reject, and the conditions are left as they are for the owning set.
     *
     * @param array<int|string, mixed> $raw A flat list or { match, conditions }.
     * @return array{match: string, conditions: array}
     */
    public static function parse( array $raw ): array {
        if ( ! self::is_shaped( $raw ) ) {
            return [
                'match'      => self::MATCH_ALL,
                'conditions' => array_values( $raw ),
            ];
        }

        return [
            'match'      => self::match_of( $raw ),
            'conditions' => is_array( $raw['conditions'] ?? null ) ? array_values( $raw['conditions'] ) : [],
        ];
    }

    /**
     * Reads the stored column. Never throws: malformed JSON reads as no conditions.
     *
     * @return array{match: string, conditions: array}
     */
    public static function decode( ?string $raw ): array {
        return [
            'match'      => self::match_of( Json::decode( $raw, [] ) ),
            'conditions' => Json::decode_list( $raw, [] ),
        ];
    }

    /**
     * Encodes for the column as the 1.0.0 {"items":[…],"schema_version":$version} list. An `any` list adds
     * "match":"any" at schema_version 2; a 1.0.0 reader ignores the key and reads it as `all`, which is stricter.
     *
     * @param array<int|string, mixed> $conditions A flat list or { match, conditions }.
     */
    public static function encode( array $conditions, int $version ): string {
        $parsed = self::parse( $conditions );

        if ( self::MATCH_ANY !== $parsed['match'] || [] === $parsed['conditions'] ) {
            return Json::encode_list( $parsed['conditions'], $version );
        }

        return Json::encode(
            [
                Json::ITEMS_KEY => $parsed['conditions'],
                self::MATCH_KEY => self::MATCH_ANY,
            ],
            self::ANY_SCHEMA_VERSION
        );
    }

    /**
     * @param array<int|string, mixed> $raw A flat list or { match, conditions }.
     * @throws \InvalidArgumentException On a malformed shape or an unknown match value.
     */
    public static function assert_structure( array $raw ): void {
        if ( ! self::is_shaped( $raw ) ) {
            return;
        }

        if ( array_key_exists( 'conditions', $raw ) && ! is_array( $raw['conditions'] ) ) {
            throw self::bad( 'conditions must be a list' );
        }

        if ( ! in_array( self::match_of( $raw ), [ self::MATCH_ALL, self::MATCH_ANY ], true ) ) {
            throw self::bad( 'match must be "all" or "any"' );
        }
    }

    /**
     * Folds verdicts by a match value: `any` needs one true, anything else needs all true.
     * No verdicts is true either way, since nothing was asked.
     *
     * @param bool[] $verdicts
     */
    public static function fold( string $mode, array $verdicts ): bool {
        if ( [] === $verdicts ) {
            return true;
        }

        if ( self::MATCH_ANY === $mode ) {
            return in_array( true, $verdicts, true );
        }

        return ! in_array( false, $verdicts, true );
    }

    /** { match, conditions } names either key; a flat list has neither. */
    private static function is_shaped( array $raw ): bool {
        return array_key_exists( 'conditions', $raw ) || array_key_exists( self::MATCH_KEY, $raw );
    }

    private static function match_of( array $node ): string {
        return isset( $node[ self::MATCH_KEY ] ) && is_scalar( $node[ self::MATCH_KEY ] ) ? (string) $node[ self::MATCH_KEY ] : self::MATCH_ALL;
    }

    private static function bad( string $why ): \InvalidArgumentException {
        return new \InvalidArgumentException( esc_html( sprintf( 'YayLoyalty: conditions %s.', $why ) ) );
    }
}
