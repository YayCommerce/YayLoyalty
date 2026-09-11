<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\CampaignConditionType;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A campaign's `conditions` — an ordered set of CampaignCondition value objects.
 *
 * STORAGE. Conditions live in the `conditions` LONGTEXT column on the campaigns
 * table; there is no conditions table. The column holds a JSON list written
 * from PHP (LONGTEXT cannot carry a SQL default). An empty list means "no
 * restrictions — the campaign applies to everyone". Lists round-trip through the
 * `{ schema_version, items: [...] }` envelope (Json::encode_list /
 * Json::decode_list), so the version key never turns the list into an object;
 * to_json() here produces exactly what Campaign::to_row() writes.
 *
 * SEMANTICS. Every condition in the set is ANDed. There is no OR and no nesting
 * — a set is a flat list with at most one entry per type.
 *
 * This class owns validation and normalisation on save. It does NOT evaluate a
 * set against an order — that is the evaluator's job (Pro) and a separate task.
 *
 * Named `CampaignConditionSet`, not the bare `ConditionSet` — rewards get their
 * own condition vocabulary (a later task) and must not collide with this one.
 */
final class CampaignConditionSet {

    /** @var CampaignCondition[] */
    private $conditions;

    /**
     * @param CampaignCondition[] $conditions
     */
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
     * Build from a list of { type, operator, value } maps. Lenient: non-array
     * entries are dropped and missing keys default. Strictness is validate().
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

    /**
     * Build from the stored column value. Total: malformed JSON yields an empty
     * set, never an exception (see Utils\Json).
     */
    public static function from_json( ?string $raw ): self {
        return self::from_array( Json::decode_list( $raw, [] ) );
    }

    /**
     * @return array<int, array{type: string, operator: string, value: mixed}>
     */
    public function to_array(): array {
        return array_map(
            static function ( CampaignCondition $condition ) {
                return $condition->to_array();
            },
            $this->conditions
        );
    }

    /**
     * Encode for the `conditions` column. Same envelope Campaign::to_row() uses,
     * keyed to the shared blob version.
     */
    public function to_json(): string {
        return Json::encode_list( $this->to_array(), Campaign::CONFIG_SCHEMA_VERSION );
    }

    /**
     * @return CampaignCondition[]
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
     *   - country and currency codes are upper-cased
     *   - role slugs are lower-cased
     *   - list values are de-duplicated
     *   - entries are ordered by evaluation cost (cheap types first), matching
     *     Enums\CampaignConditionType, so an evaluator short-circuits without re-sorting
     *
     * Best-effort and non-throwing: an unknown type is passed through untouched
     * for validate() to reject.
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
     * @param mixed                $value
     * @return mixed
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
                // A bare list of codes was supplied; move it under `countries`
                // and leave `source` absent for validate() to flag.
                $countries = $value;
                $value     = [];
            }

            $countries = array_map( 'strtoupper', array_map( 'strval', $countries ) );

            $value              = is_array( $value ) ? $value : [];
            $value['countries'] = array_values( array_unique( $countries ) );

            return $value;
        }

        if ( in_array( 'array', $hints, true ) && ! is_array( $value ) ) {
            $value = null === $value ? [] : [ $value ];
        }

        if ( ! is_array( $value ) ) {
            return $value;
        }

        if ( in_array( 'int', $hints, true ) ) {
            $value = array_map( 'intval', $value );
        }

        if ( in_array( 'uppercase', $hints, true ) ) {
            $value = array_map( 'strtoupper', array_map( 'strval', $value ) );
        }

        if ( in_array( 'lowercase', $hints, true ) ) {
            $value = array_map( 'strtolower', array_map( 'strval', $value ) );
        }

        if ( in_array( 'dedupe', $hints, true ) ) {
            $value = array_values( array_unique( $value ) );
        }

        return $value;
    }

    /* ─── validation ────────────────────────────────────────────────── */

    /**
     * Non-throwing check for the save boundary — mirrors
     * Campaign::config_is_valid(). Normalises first, then validates the result.
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
     * @param mixed                $value
     */
    private static function validate_value( string $type, array $spec, string $operator, $value ): void {
        switch ( $spec['value'] ?? '' ) {
            case 'string_list':
            case 'int_list':
            case 'currency_list':
                self::assert_non_empty_list( $type, $value );

                foreach ( $value as $item ) {
                    if ( 'int_list' === $spec['value'] && ! self::is_int_like( $item ) ) {
                        throw self::bad( $type, 'expects a list of integer ids' );
                    }

                    if ( 'string_list' === $spec['value'] && ( ! is_string( $item ) || '' === $item ) ) {
                        throw self::bad( $type, 'expects a list of non-empty strings' );
                    }

                    if ( 'currency_list' === $spec['value'] && ! self::is_currency_code( (string) $item ) ) {
                        throw self::bad( $type, sprintf( '"%s" is not an ISO 4217 currency code', (string) $item ) );
                    }
                }

                if ( isset( $spec['max'] ) && count( $value ) > (int) $spec['max'] ) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'YayLoyalty: "%s" accepts at most %d ids. For a larger segment, use a user_role or tier condition instead.',
                            $type,
                            (int) $spec['max']
                        )
                    );
                }
                break;

            case 'country_list':
                if ( ! is_array( $value ) ) {
                    throw self::bad( $type, 'expects { countries: [...], source: shipping|billing|any }' );
                }

                self::assert_non_empty_list( $type, $value['countries'] ?? null );

                foreach ( $value['countries'] as $code ) {
                    if ( ! self::is_country_code( (string) $code ) ) {
                        throw self::bad( $type, sprintf( '"%s" is not an ISO 3166-1 alpha-2 country code', (string) $code ) );
                    }
                }

                $sources = $spec['source_values'] ?? [];

                if ( ! isset( $value['source'] ) || ! in_array( $value['source'], $sources, true ) ) {
                    throw self::bad(
                        $type,
                        sprintf(
                            'requires a "source" of %s — no default, because the shipping address is absent on virtual and downloadable orders',
                            implode( ' | ', $sources )
                        )
                    );
                }
                break;

            case 'int':
                if ( CampaignConditionType::OP_BETWEEN === $operator ) {
                    self::assert_between_pair( $type, $value, true );
                } elseif ( ! self::is_int_like( $value ) ) {
                    throw self::bad( $type, 'expects an integer' );
                }
                break;

            case 'money':
                if ( ! is_array( $value ) ) {
                    throw self::bad( $type, 'expects { amount, currency } — never a bare number' );
                }

                if ( ! isset( $value['currency'] ) || ! self::is_currency_code( (string) $value['currency'] ) ) {
                    throw self::bad(
                        $type,
                        'requires a "currency" that is an ISO 4217 code — only orders in that currency count toward the total, with no exchange-rate conversion'
                    );
                }

                $amount = $value['amount'] ?? null;

                if ( CampaignConditionType::OP_BETWEEN === $operator ) {
                    self::assert_between_pair( $type, $amount, false );
                } elseif ( is_bool( $amount ) || ! is_numeric( $amount ) ) {
                    throw self::bad( $type, 'expects a numeric "amount"' );
                }
                break;

            case 'tier':
                // Unreachable today: tier is not in CampaignConditionType::all(), so the
                // type check in validate() already threw. Left for when the tier
                // schema task lifts the gate.
                break;
        }
    }

    /**
     * @param mixed $value
     */
    private static function assert_non_empty_list( string $type, $value ): void {
        if ( ! is_array( $value ) || [] === $value ) {
            throw self::bad( $type, 'value must be a non-empty array' );
        }
    }

    /**
     * @param mixed $value
     */
    private static function assert_between_pair( string $type, $value, bool $integers ): void {
        if ( ! is_array( $value ) || 2 !== count( $value ) || ! array_key_exists( 0, $value ) || ! array_key_exists( 1, $value ) ) {
            throw self::bad( $type, '`between` takes a two-element [low, high] array' );
        }

        $low  = $value[0];
        $high = $value[1];

        $ok = $integers
            ? ( self::is_int_like( $low ) && self::is_int_like( $high ) )
            : ( ! is_bool( $low ) && is_numeric( $low ) && ! is_bool( $high ) && is_numeric( $high ) );

        if ( ! $ok ) {
            throw self::bad( $type, '`between` bounds must be numeric' );
        }

        if ( (float) $low > (float) $high ) {
            throw self::bad( $type, '`between` needs low <= high' );
        }
    }

    /**
     * @param mixed $value
     */
    private static function is_int_like( $value ): bool {
        return ! is_bool( $value ) && is_numeric( $value ) && floor( (float) $value ) === (float) $value;
    }

    /**
     * ISO 4217. Format-checked always; membership-checked against WooCommerce's
     * currency list when it is loaded (this plugin requires WooCommerce, so that
     * is the normal path — the fallback keeps the value object usable in isolation).
     */
    private static function is_currency_code( string $code ): bool {
        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
            return false;
        }

        if ( function_exists( 'get_woocommerce_currencies' ) ) {
            return array_key_exists( $code, get_woocommerce_currencies() );
        }

        return true;
    }

    /**
     * ISO 3166-1 alpha-2. Same approach as is_currency_code().
     */
    private static function is_country_code( string $code ): bool {
        if ( 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
            return false;
        }

        if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) && is_callable( [ WC()->countries, 'get_countries' ] ) ) {
            return array_key_exists( $code, WC()->countries->get_countries() );
        }

        return true;
    }

    private static function bad( string $type, string $why ): \InvalidArgumentException {
        return new \InvalidArgumentException(
            sprintf( 'YayLoyalty: condition "%s" %s.', $type, $why )
        );
    }
}
