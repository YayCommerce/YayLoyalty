<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\RewardConditionType;
use YayLoyalty\Utils\ConditionGroups;
use YayLoyalty\Utils\ConditionValueRules;

defined( 'ABSPATH' ) || exit;

/**
 * A reward's `conditions`: groups joined by all/any (Utils\ConditionGroups), at most one entry per type in a group, stored as JSON on the rewards table.
 * An empty list makes the reward available to everyone. A condition is a fact about the customer.
 * Reward-level properties such as min_order belong in `config`.
 */
final class RewardConditionSet {

    /** @var string */
    private $match;

    /** @var array<int, array{match: string, conditions: RewardCondition[]}> */
    private $groups;

    /**
     * @param array<int, array{match?: string, conditions?: array}> $groups Each group's conditions are RewardCondition objects.
     */
    public function __construct( array $groups = [], string $root_match = ConditionGroups::MATCH_ALL ) {
        $this->match  = $root_match;
        $this->groups = [];

        foreach ( $groups as $group ) {
            $this->groups[] = [
                'match'      => (string) ( $group['match'] ?? ConditionGroups::MATCH_ALL ),
                'conditions' => array_values(
                    array_filter(
                        (array) ( $group['conditions'] ?? [] ),
                        static function ( $condition ) {
                            return $condition instanceof RewardCondition;
                        }
                    )
                ),
            ];
        }
    }

    /**
     * Builds from a flat list or a tree of { type, operator, value } maps. Lenient: validate() is the strict check.
     *
     * @param array<int|string, mixed> $raw
     */
    public static function from_array( array $raw ): self {
        $tree   = ConditionGroups::parse( $raw );
        $groups = [];

        foreach ( $tree['groups'] as $group ) {
            $conditions = [];

            foreach ( $group['conditions'] as $entry ) {
                if ( is_array( $entry ) ) {
                    $conditions[] = RewardCondition::from_array( $entry );
                }
            }

            $groups[] = [
                'match'      => $group['match'],
                'conditions' => $conditions,
            ];
        }

        return new self( $groups, $tree['match'] );
    }

    /** Builds from the stored column value. Malformed JSON yields an empty set, never an exception. */
    public static function from_json( ?string $raw ): self {
        return self::from_array( ConditionGroups::decode( $raw ) );
    }

    /** @return array{match: string, groups: array<int, array{match: string, conditions: array}>} */
    public function to_array(): array {
        return [
            'match'  => $this->match,
            'groups' => array_map(
                static function ( array $group ) {
                    return [
                        'match'      => $group['match'],
                        'conditions' => array_map(
                            static function ( RewardCondition $condition ) {
                                return $condition->to_array();
                            },
                            $group['conditions']
                        ),
                    ];
                },
                $this->groups
            ),
        ];
    }

    /** Encodes for the `conditions` column, in the envelope Reward::to_row() writes. */
    public function to_json(): string {
        return ConditionGroups::encode( $this->to_array(), Reward::CONFIG_SCHEMA_VERSION );
    }

    /** How the groups combine: ConditionGroups::MATCH_ALL or MATCH_ANY. */
    public function match(): string {
        return $this->match;
    }

    /** @return array<int, array{match: string, conditions: RewardCondition[]}> */
    public function groups(): array {
        return $this->groups;
    }

    /** @return RewardCondition[] Every group's conditions, in order. */
    public function all(): array {
        return [] === $this->groups ? [] : array_merge( ...array_column( $this->groups, 'conditions' ) );
    }

    public function is_empty(): bool {
        return [] === $this->all();
    }

    public function count(): int {
        return count( $this->all() );
    }

    /**
     * Canonicalises a flat list or a tree for storage: the tree shape, each group normalised
     * by normalize_list(), and empty groups dropped. Never throws.
     *
     * @param array<int|string, mixed> $raw
     * @return array{match: string, groups: array<int, array{match: string, conditions: array}>}
     */
    public static function normalize( array $raw ): array {
        $tree   = ConditionGroups::parse( $raw );
        $groups = [];

        foreach ( $tree['groups'] as $group ) {
            $conditions = self::normalize_list( $group['conditions'] );

            if ( [] !== $conditions ) {
                $groups[] = [
                    'match'      => $group['match'],
                    'conditions' => $conditions,
                ];
            }
        }

        return [
            'match'  => $tree['match'],
            'groups' => $groups,
        ];
    }

    /**
     * Canonicalises one group's list: scalars widened to lists, ids
     * cast, roles lower-cased, lists de-duplicated, entries ordered
     * cheap-first. Never throws: unknown types pass through for validate().
     *
     * @param array<int, mixed> $list
     * @return array<int, array{type: string, operator: string, value: mixed}>
     */
    private static function normalize_list( array $list ): array {
        $schema = RewardConditionType::schema();
        $out    = [];

        foreach ( $list as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['type'] ) ) {
                continue;
            }

            $type  = (string) $entry['type'];
            $value = $entry['value'] ?? null;
            $spec  = $schema[ $type ] ?? null;

            if ( null !== $spec ) {
                $hints = isset( $spec['normalize'] ) && is_array( $spec['normalize'] ) ? $spec['normalize'] : [];
                $value = ConditionValueRules::normalize_scalar( $hints, $value );
            }

            $out[] = [
                'type'     => $type,
                'operator' => isset( $entry['operator'] ) ? (string) $entry['operator'] : '',
                'value'    => $value,
            ];
        }

        $order = RewardConditionType::all_known();

        usort(
            $out,
            static function ( array $a, array $b ) use ( $order ) {
                $ia = array_search( $a['type'], $order, true );
                $ib = array_search( $b['type'], $order, true );
                $ia = false === $ia ? PHP_INT_MAX : $ia;
                $ib = false === $ib ? PHP_INT_MAX : $ib;

                return $ia <=> $ib;
            }
        );

        return $out;
    }

    /**
     * Non-throwing check for the save boundary. It checks the raw structure, then normalises.
     *
     * @param array<int|string, mixed> $raw A flat list or a tree.
     */
    public static function is_valid( array $raw ): bool {
        try {
            ConditionGroups::assert_structure( $raw );
            self::from_array( self::normalize( $raw ) )->validate();

            return true;
        } catch ( \InvalidArgumentException $e ) {
            return false;
        }
    }

    /**
     * @throws \InvalidArgumentException On the first problem: a malformed structure, an unknown type,
     *         an operator the type does not accept, a value of the wrong
     *         shape, an empty list value, a duplicate type in a group, or customer_ids
     *         past its cap.
     */
    public function validate(): void {
        ConditionGroups::assert_structure( $this->to_array() );

        foreach ( $this->groups as $group ) {
            self::validate_group( $group['conditions'] );
        }
    }

    /**
     * One group's checks. A type may repeat across groups, not within one.
     *
     * @param RewardCondition[] $conditions
     */
    private static function validate_group( array $conditions ): void {
        $schema = RewardConditionType::schema();
        $seen   = [];

        foreach ( $conditions as $condition ) {
            $type = $condition->get_type();

            if ( ! RewardConditionType::is_valid( $type ) ) {
                throw ConditionValueRules::bad( esc_html( $type ), 'is not a known condition type' );
            }

            if ( isset( $seen[ $type ] ) ) {
                throw ConditionValueRules::bad( esc_html( $type ), 'appears more than once in a group. Conditions in a group are combined — use a single entry' );
            }
            $seen[ $type ] = true;

            $spec     = $schema[ $type ];
            $operator = $condition->get_operator();

            if ( ! in_array( $operator, $spec['operators'], true ) ) {
                throw ConditionValueRules::bad(
                    esc_html( $type ),
                    esc_html( sprintf( 'does not allow operator "%s". Allowed: %s', $operator, implode( ', ', $spec['operators'] ) ) )
                );
            }

            self::validate_value( $type, $spec, $operator, $condition->get_value() );
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function validate_value( string $type, array $spec, string $operator, $value ): void {
        switch ( $spec['value'] ?? '' ) {
            case 'string_list':
                ConditionValueRules::validate_list( $type, $value, 'string' );
                break;

            case 'int_list':
                ConditionValueRules::validate_list( $type, $value, 'int', isset( $spec['max'] ) ? (int) $spec['max'] : null );
                break;

            case 'int':
                ConditionValueRules::validate_int( $type, $operator, $value );
                break;
        }
    }
}
