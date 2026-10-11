<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Utils\ConditionValueRules;
use YayLoyalty\Utils\IsoCodes;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A campaign's `conditions`: a flat ANDed list, at most one entry per type, stored as JSON in the campaigns table.
 * An empty list applies to everyone. This class validates and normalises on save, and
 * Pro\Services\ConditionsEvaluator evaluates. It shares only Utils\ConditionValueRules with RewardConditionSet.
 */
final class CampaignConditionSet {

    /** @var CampaignCondition[] */
    private $conditions;

    /** @param CampaignCondition[] $conditions */
    public function __construct( array $conditions = [] ) {
        $this->conditions = array_values(
            array_filter(
                $conditions,
                static function ( $condition ) {
                    return $condition instanceof CampaignCondition;
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
                $conditions[] = CampaignCondition::from_array( $entry );
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
            static function ( CampaignCondition $condition ) {
                return $condition->to_array();
            },
            $this->conditions
        );
    }

    /** Encodes for the `conditions` column, in the envelope Campaign::to_row() writes. */
    public function to_json(): string {
        return Json::encode_list( $this->to_array(), Campaign::CONFIG_SCHEMA_VERSION );
    }

    /** @return CampaignCondition[] */
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
     * Canonicalises a raw list for storage: scalars widened to lists, ids cast,
     * codes upper-cased, roles lower-cased, lists de-duplicated, entries
     * ordered cheap-first for evaluation. Never throws: unknown types pass
     * through for validate() to reject.
     *
     * @param array<int, mixed> $list
     * @return array<int, array{type: string, operator: string, value: mixed}>
     */
    public static function normalize( array $list ): array {
        $schema = CampaignConditionType::schema();
        $out    = [];

        foreach ( $list as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['type'] ) ) {
                continue;
            }

            $type  = (string) $entry['type'];
            $value = $entry['value'] ?? null;
            $spec  = $schema[ $type ] ?? null;

            if ( null !== $spec ) {
                $value = self::normalize_value( $spec, $value );
            }

            $out[] = [
                'type'     => $type,
                'operator' => isset( $entry['operator'] ) ? (string) $entry['operator'] : '',
                'value'    => $value,
            ];
        }

        $order = CampaignConditionType::all_known();

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
     * @param array<string, mixed> $spec
     */
    private static function normalize_value( array $spec, $value ) {
        $shape = $spec['value'] ?? '';
        $hints = isset( $spec['normalize'] ) && is_array( $spec['normalize'] ) ? $spec['normalize'] : [];

        if ( 'money' === $shape ) {
            if ( is_array( $value ) && isset( $value['currency'] ) ) {
                $value['currency'] = strtoupper( (string) $value['currency'] );
            }

            return $value;
        }

        if ( 'country_list' === $shape ) {
            $countries = [];

            if ( is_array( $value ) && isset( $value['countries'] ) ) {
                $countries = is_array( $value['countries'] ) ? $value['countries'] : [ $value['countries'] ];
            } elseif ( is_array( $value ) && ! isset( $value['source'] ) ) {
                // A bare list of codes: move under `countries`, leave `source` absent for validate() to flag.
                $countries = $value;
                $value     = [];
            }

            $countries = array_map( 'strtoupper', array_map( 'strval', $countries ) );

            $value              = is_array( $value ) ? $value : [];
            $value['countries'] = array_values( array_unique( $countries ) );

            return $value;
        }

        return ConditionValueRules::normalize_scalar( $hints, $value );
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
     *         an operator the type does not accept, a value of the wrong shape,
     *         an empty list value, a duplicate type, a missing required sub-key
     *         (currency, source), or customer_ids past its cap.
     */
    public function validate(): void {
        $schema = CampaignConditionType::schema();
        $seen   = [];

        foreach ( $this->conditions as $condition ) {
            $type = $condition->get_type();

            if ( ! CampaignConditionType::is_valid( $type ) ) {
                throw new \InvalidArgumentException(
                    sprintf( 'YayLoyalty: unknown condition type "%s".', $type )
                );
            }

            if ( isset( $seen[ $type ] ) ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'YayLoyalty: condition type "%s" appears more than once. Conditions are ANDed — use a single entry.',
                        $type
                    )
                );
            }
            $seen[ $type ] = true;

            $spec     = $schema[ $type ];
            $operator = $condition->get_operator();

            if ( ! in_array( $operator, $spec['operators'], true ) ) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'YayLoyalty: operator "%s" is not allowed for condition "%s". Allowed: %s.',
                        $operator,
                        $type,
                        implode( ', ', $spec['operators'] )
                    )
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
                ConditionValueRules::validate_list( $type, $value, 'string', isset( $spec['max'] ) ? (int) $spec['max'] : null );
                break;

            case 'int_list':
                ConditionValueRules::validate_list( $type, $value, 'int', isset( $spec['max'] ) ? (int) $spec['max'] : null );
                break;

            case 'currency_list':
                ConditionValueRules::assert_non_empty_list( $type, $value );

                foreach ( $value as $item ) {
                    if ( ! IsoCodes::is_currency( (string) $item ) ) {
                        throw ConditionValueRules::bad( $type, sprintf( '"%s" is not an ISO 4217 currency code', (string) $item ) );
                    }
                }

                if ( isset( $spec['max'] ) && count( $value ) > (int) $spec['max'] ) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'YayLoyalty: "%s" accepts at most %d ids. For a larger segment, use a user_role condition instead.',
                            $type,
                            (int) $spec['max']
                        )
                    );
                }
                break;

            case 'country_list':
                if ( ! is_array( $value ) ) {
                    throw ConditionValueRules::bad( $type, 'expects { countries: [...], source: shipping|billing|any }' );
                }

                ConditionValueRules::assert_non_empty_list( $type, $value['countries'] ?? null );

                foreach ( $value['countries'] as $code ) {
                    if ( ! IsoCodes::is_country( (string) $code ) ) {
                        throw ConditionValueRules::bad( $type, sprintf( '"%s" is not an ISO 3166-1 alpha-2 country code', (string) $code ) );
                    }
                }

                $sources = $spec['source_values'] ?? [];

                if ( ! isset( $value['source'] ) || ! in_array( $value['source'], $sources, true ) ) {
                    throw ConditionValueRules::bad(
                        $type,
                        sprintf(
                            'requires a "source" of %s — no default, because the shipping address is absent on virtual and downloadable orders',
                            implode( ' | ', $sources )
                        )
                    );
                }
                break;

            case 'int':
                ConditionValueRules::validate_int( $type, $operator, $value );
                break;

            case 'money':
                if ( ! is_array( $value ) ) {
                    throw ConditionValueRules::bad( $type, 'expects { amount, currency } — never a bare number' );
                }

                if ( ! isset( $value['currency'] ) || ! IsoCodes::is_currency( (string) $value['currency'] ) ) {
                    throw ConditionValueRules::bad(
                        $type,
                        'requires a "currency" that is an ISO 4217 code — only orders in that currency count toward the total, with no exchange-rate conversion'
                    );
                }

                $amount = $value['amount'] ?? null;

                if ( CampaignConditionType::OP_BETWEEN === $operator ) {
                    ConditionValueRules::assert_between_pair( $type, $amount, false );
                } elseif ( is_bool( $amount ) || ! is_numeric( $amount ) ) {
                    throw ConditionValueRules::bad( $type, 'expects a numeric "amount"' );
                }
                break;
        }
    }
}
