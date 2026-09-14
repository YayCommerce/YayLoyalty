<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\RewardConditionType;
use YayLoyalty\Utils\ConditionValueRules;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A reward's `conditions` — an ordered set of RewardCondition value objects.
 *
 * STORAGE. Conditions live in the `conditions` LONGTEXT column already on the
 * rewards table (see RewardsTable) — no new table. The column holds a JSON
 * list written from PHP (LONGTEXT cannot carry a SQL default). An empty list
 * means "no restrictions — the reward is available to everyone". Lists
 * round-trip through the `{ schema_version, items: [...] }` envelope
 * (Json::encode_list / Json::decode_list), same as campaigns.
 *
 * SEMANTICS. Every condition in the set is ANDed. There is no OR and no
 * nesting — a set is a flat list with at most one entry per type.
 *
 * CONDITIONS VS CONFIG. A condition is a fact about the customer being
 * evaluated — `redemption_count` is "how many times THIS customer has claimed
 * THIS reward". A reward-level property that is not customer-specific, such
 * as `max_total` (a global cap on the reward across every customer), belongs
 * in `config`, not here. See Models\Reward's class docblock.
 */
final class RewardConditionSet {

    /** @var RewardCondition[] */
    private $conditions;

    /**
     * @param RewardCondition[] $conditions
     */
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
     * Build from a list of { type, operator, value } maps. Lenient: non-array
     * entries are dropped and missing keys default. Strictness is validate().
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

    /**
     * Build from the stored column value. Total: malformed JSON yields an
     * empty set, never an exception (see Utils\Json).
     */
    public static function from_json( ?string $raw ): self {
        return self::from_array( Json::decode_list( $raw, [] ) );
    }

    /**
     * @return array<int, array{type: string, operator: string, value: mixed}>
     */
    public function to_array(): array {
        return array_map(
            static function ( RewardCondition $condition ) {
                return $condition->to_array();
            },
            $this->conditions
        );
    }

    /**
     * Encode for the `conditions` column. Same envelope Reward::to_row() uses,
     * keyed to the shared blob version.
     */
    public function to_json(): string {
        return Json::encode_list( $this->to_array(), Reward::CONFIG_SCHEMA_VERSION );
    }

    /**
     * @return RewardCondition[]
     */
    public function all(): array {
        return $this->conditions;
    }

    public function is_empty(): bool {
        return [] === $this->conditions;
    }

    public function count(): int {
        return count( $this->conditions );
    }

    /* ─── normalisation ─────────────────────────────────────────────── */

    /**
     * Canonicalise a raw conditions list for storage:
     *
     *   - a scalar becomes a single-element array where the type expects a list
     *   - ids are cast to int
     *   - role slugs are lower-cased
     *   - list values are de-duplicated
     *   - entries are ordered by evaluation cost (cheap types first), matching
     *     Enums\RewardConditionType, so an evaluator short-circuits without
     *     re-sorting
     *
     * Best-effort and non-throwing: an unknown type is passed through
     * untouched for validate() to reject.
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

    /* ─── validation ────────────────────────────────────────────────── */

    /**
     * Non-throwing check for the save boundary — mirrors
     * Reward::config_is_valid(). Normalises first, then validates the result.
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
                throw self::bad( $type, 'is not a known condition type' );
            }

            if ( isset( $seen[ $type ] ) ) {
                throw self::bad( $type, 'appears more than once. Conditions are ANDed — use a single entry' );
            }
            $seen[ $type ] = true;

            $spec     = $schema[ $type ];
            $operator = $condition->get_operator();

            if ( ! in_array( $operator, $spec['operators'], true ) ) {
                throw self::bad(
                    $type,
                    sprintf( 'does not allow operator "%s". Allowed: %s', $operator, implode( ', ', $spec['operators'] ) )
                );
            }

            self::validate_value( $type, $spec, $operator, $condition->get_value() );
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param mixed                $value
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
                ConditionValueRules::validate_int( $type, $operator, $value, isset( $spec['min'] ) ? (int) $spec['min'] : null );
                break;

            case 'tier':
                // Unreachable today: tier is not in RewardConditionType::all(), so
                // the type check in validate() already threw. Left for when the
                // tier schema task lifts the gate.
                break;
        }
    }

    private static function bad( string $type, string $why ): \InvalidArgumentException {
        return new \InvalidArgumentException(
            sprintf( 'YayLoyalty: condition "%s" %s.', $type, $why )
        );
    }
}
