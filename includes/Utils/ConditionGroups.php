<?php
namespace YayLoyalty\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The structure of a campaign's or a reward's `conditions`: groups of conditions, each group and the root
 * joined by `all` (AND) or `any` (OR). A flat list, as 1.0.0 stored and as any API caller may still send,
 * reads as one `all` group.
 *
 * Tree: { match: all|any, groups: [ { match: all|any, conditions: [ {type, operator, value} ] } ] }.
 */
final class ConditionGroups {

    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    public const MAX_GROUPS = 10;

    /** The column's schema_version for a real grouping. One `all` group keeps the 1.0.0 list envelope. */
    public const GROUPS_SCHEMA_VERSION = 2;

    /**
     * Either shape to the tree. Lenient: match values pass through for assert_structure() to reject,
     * and the conditions are left as they are for the owning set.
     *
     * @param array<int|string, mixed> $raw A flat list or a tree.
     * @return array{match: string, groups: array<int, array{match: string, conditions: array}>}
     */
    public static function parse( array $raw ): array {
        if ( ! self::is_tree( $raw ) ) {
            $list = array_values( $raw );

            return [
                'match'  => self::MATCH_ALL,
                'groups' => [] === $list ? [] : [
                    [
                        'match'      => self::MATCH_ALL,
                        'conditions' => $list,
                    ],
                ],
            ];
        }

        $groups = [];

        foreach ( is_array( $raw['groups'] ?? null ) ? $raw['groups'] : [] as $group ) {
            if ( ! is_array( $group ) ) {
                continue;
            }

            $groups[] = [
                'match'      => self::match_of( $group ),
                'conditions' => is_array( $group['conditions'] ?? null ) ? array_values( $group['conditions'] ) : [],
            ];
        }

        return [
            'match'  => self::match_of( $raw ),
            'groups' => $groups,
        ];
    }

    /**
     * Reads the stored column into the tree. Never throws: malformed JSON reads as no conditions.
     *
     * @return array{match: string, groups: array<int, array{match: string, conditions: array}>}
     */
    public static function decode( ?string $raw ): array {
        $decoded = Json::decode( $raw, [] );

        if ( isset( $decoded['groups'] ) ) {
            return self::parse( $decoded );
        }

        return self::parse( Json::decode_list( $raw, [] ) );
    }

    /**
     * Encodes for the column. No groups or one `all` group is written as the 1.0.0
     * {"schema_version":$version,"items":[…]} list, which a 1.0.0 reader still understands;
     * anything else as a schema_version 2 tree.
     *
     * @param array<int|string, mixed> $conditions A flat list or a tree.
     */
    public static function encode( array $conditions, int $version ): string {
        $tree   = self::parse( $conditions );
        $groups = $tree['groups'];

        if ( [] === $groups ) {
            return Json::encode_list( [], $version );
        }

        if ( 1 === count( $groups ) && self::MATCH_ANY !== $groups[0]['match'] ) {
            return Json::encode_list( $groups[0]['conditions'], $version );
        }

        return Json::encode( $tree, self::GROUPS_SCHEMA_VERSION );
    }

    /**
     * @param array<int|string, mixed> $raw A flat list or a tree.
     * @throws \InvalidArgumentException On a malformed tree, an unknown match value, or more than MAX_GROUPS groups.
     */
    public static function assert_structure( array $raw ): void {
        if ( ! self::is_tree( $raw ) ) {
            return;
        }

        if ( array_key_exists( 'groups', $raw ) && ! is_array( $raw['groups'] ) ) {
            throw self::bad( 'groups must be a list' );
        }

        self::assert_match( $raw );

        $groups = $raw['groups'] ?? [];

        if ( count( $groups ) > self::MAX_GROUPS ) {
            throw self::bad( esc_html( sprintf( 'accept at most %d groups', self::MAX_GROUPS ) ) );
        }

        foreach ( $groups as $group ) {
            if ( ! is_array( $group ) ) {
                throw self::bad( 'groups must be objects' );
            }

            if ( array_key_exists( 'conditions', $group ) && ! is_array( $group['conditions'] ) ) {
                throw self::bad( 'a group\'s conditions must be a list' );
            }

            self::assert_match( $group );
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

    /** A tree names its groups or its match; a flat list has neither key. */
    private static function is_tree( array $raw ): bool {
        return array_key_exists( 'groups', $raw ) || array_key_exists( 'match', $raw );
    }

    private static function match_of( array $node ): string {
        return isset( $node['match'] ) && is_scalar( $node['match'] ) ? (string) $node['match'] : self::MATCH_ALL;
    }

    private static function assert_match( array $node ): void {
        if ( ! in_array( self::match_of( $node ), [ self::MATCH_ALL, self::MATCH_ANY ], true ) ) {
            throw self::bad( 'match must be "all" or "any"' );
        }
    }

    private static function bad( string $why ): \InvalidArgumentException {
        return new \InvalidArgumentException( esc_html( sprintf( 'YayLoyalty: condition groups %s.', $why ) ) );
    }
}
