<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\CampaignConditionType;

defined( 'ABSPATH' ) || exit;

/**
 * One campaign eligibility rule: { type, operator, value }. An immutable value object.
 * CampaignConditionSet validates `value` against CampaignConditionType::schema().
 */
final class CampaignCondition {

    /** @var string */
    private $type;

    /** @var string */
    private $operator;

    /** @var mixed */
    private $value;

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

    /** @return array{type: string, operator: string, value: mixed} */
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

    public function get_value() {
        return $this->value;
    }

    /**
     * The value as a list. A scalar is wrapped so callers never branch.
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
