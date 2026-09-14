<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\CampaignConditionType;

defined( 'ABSPATH' ) || exit;

/**
 * One eligibility rule: { type, operator, value }.
 *
 * A value object. Immutable by convention — PHP 7.4 has no `readonly`, so this
 * is private properties and getters only, no setters. Build a changed one with
 * from_array() rather than mutating.
 *
 * `value` is polymorphic; its accepted shapes are defined by
 * Enums\CampaignConditionType::schema() — a list for the `in` / `not_in` types, an
 * integer (or a [low, high] pair for `between`) for the count types, a
 * { amount, currency } map for `lifetime_spend`, a { countries, source } map for
 * `country`. This class only holds and exposes the value. Validation and
 * normalisation are Models\CampaignConditionSet's job.
 */
final class CampaignCondition {

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
