<?php
namespace YayLoyalty\Models;

defined( 'ABSPATH' ) || exit;

/**
 * One reward eligibility rule: { type, operator, value }.
 *
 * A value object. Immutable by convention — PHP 7.4 has no `readonly`, so this
 * is private properties and getters only, no setters. Build a changed one with
 * from_array() rather than mutating.
 *
 * `value` is polymorphic; its accepted shapes are defined by
 * Enums\RewardConditionType::schema() — a list for the `in` / `not_in` types,
 * an integer (or a [low, high] pair for `between`) for `lifetime_points`, a
 * non-negative integer for `redemption_count`. This class only holds and
 * exposes the value; validation and normalisation are
 * Models\RewardConditionSet's job.
 *
 * Deliberately smaller than Models\CampaignCondition: rewards' vocabulary has
 * no `money` or `country` shaped values, so there is no money()/country()
 * accessor here to go with them.
 */
final class RewardCondition {

    /** @var string */
    private $type;

    /** @var string */
    private $operator;

    /** @var mixed */
    private $value;

    /**
     * @param mixed $value
     */
    public function __construct( string $type, string $operator, $value ) {
        $this->type     = $type;
        $this->operator = $operator;
        $this->value    = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function from_array( array $data ): self {
        return new self(
            (string) ( $data['type'] ?? '' ),
            (string) ( $data['operator'] ?? '' ),
            $data['value'] ?? null
        );
    }

    /**
     * @return array{type: string, operator: string, value: mixed}
     */
    public function to_array(): array {
        return [
            'type'     => $this->type,
            'operator' => $this->operator,
            'value'    => $this->value,
        ];
    }

    public function get_type(): string {
        return $this->type;
    }

    public function get_operator(): string {
        return $this->operator;
    }

    /**
     * @return mixed
     */
    public function get_value() {
        return $this->value;
    }

    /**
     * The value as a plain list — for the `in` / `not_in` types. A scalar is
     * wrapped so callers never branch on "array or not".
     *
     * @return array<int, mixed>
     */
    public function value_as_list(): array {
        if ( is_array( $this->value ) ) {
            return array_values( $this->value );
        }

        return null === $this->value ? [] : [ $this->value ];
    }
}
