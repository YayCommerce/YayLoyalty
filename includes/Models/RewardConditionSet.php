<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\RewardConditionType;
use YayLoyalty\Utils\ConditionValueRules;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A reward's `conditions`: a flat ANDed list, at most one entry per type, stored as JSON on the rewards table.
 * An empty list makes the reward available to everyone. A condition is a fact about the customer.
 * Reward-level properties such as min_order belong in `config`.
 */
final class RewardConditionSet {

    /** @var RewardCondition[] */
    private $conditions;

    /** @param RewardCondition[] $conditions */
    public function __construct( array $conditions = [] ) {
        $this->conditions = array_values(
            array_filter(
                $conditions,
                static function ( $condition ) {
                    return $condition instanceof RewardCondition;
                }
            )
        );
    }

    /**
     * Builds from { type, operator, value } maps. Lenient: validate() is the strict check.
     *
     * @param array<int, mixed> $list
     */
    public static function from_array( array $list ): self {
        $conditions = [];

        foreach ( $list as $entry ) {
            if ( is_array( $entry ) ) {
                $conditions[] = RewardCondition::from_array( $entry );
            }
        }

        return new self( $conditions );
    }

    /** Builds from the stored column value. Malformed JSON yields an empty set, never an exception. */
    public static function from_json( ?string $raw ): self {
        return self::from_array( Json::decode_list( $raw, [] ) );
    }

    /** @return array<int, array{type: string, operator: string, value: mixed}> */
    public function to_array(): array {
        return array_map(
            static function ( RewardCondition $condition ) {
                return $condition->to_array();
            },
            $this->conditions
        );
    }

    /** Encodes for the `conditions` column, in the envelope Reward::to_row() writes. */
    public function to_json(): string {
        return Json::encode_list( $this->to_array(), Reward::CONFIG_SCHEMA_VERSION );
    }

    /** @return RewardCondition[] */
    public function all(): array {
        return $this->conditions;
    }

    public function is_empty(): bool {
        return [] === $this->conditions;
    }

    public function count(): int {
        return count( $this->conditions );
    }

    /**
     * Canonicalises a raw list for storage: scalars widened to lists, ids
     * cast, roles lower-cased, lists de-duplicated, entries ordered
     * cheap-first. Never throws: unknown types pass through for validate().
     *
     * @param array<int, mixed> $list
     * @return array<int, array{type: string, operator: string, value: mixed}>
     */
    public static function normalize( array $list ): array {
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
     * Non-throwing check for the save boundary. It normalises first.
     *
     * @param array<int, mixed> $list
     */
    public static function is_valid( array $list ): bool {
        try {
            self::from_array( self::normalize( $list ) )->validate();

            return true;
        } catch ( \InvalidArgumentException $e ) {
            return false;
        }
    }

    /**
     * @throws \InvalidArgumentException On the first problem: an unknown type,
     *         an operator the type does not accept, a value of the wrong
     *         shape, an empty list value, a duplicate type, or customer_ids
     *         past its cap.
     */
    public function validate(): void {
        $schema = RewardConditionType::schema();
        $seen   = [];

        foreach ( $this->conditions as $condition ) {
            $type = $condition->get_type();

            if ( ! RewardConditionType::is_valid( $type ) ) {
                throw ConditionValueRules::bad( esc_html( $type ), 'is not a known condition type' );
            }

            if ( isset( $seen[ $type ] ) ) {
                throw ConditionValueRules::bad( esc_html( $type ), 'appears more than once. Conditions are ANDed — use a single entry' );
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

            case 'tier':
                if ( ! ConditionValueRules::is_int_like( $value ) || (int) $value <= 0 ) {
                    throw ConditionValueRules::bad( esc_html( $type ), 'expects a positive tier id' );
                }
                break;
        }
    }
}
