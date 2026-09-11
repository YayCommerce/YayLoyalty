<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The vocabulary for a campaign's `conditions` — the eligibility rules ANDed
 * together to decide whether a campaign applies to a given order.
 *
 * PHP 7.4 has no enums, so this is a final class of constants plus a per-type
 * map (schema()). The map is the single source the save-time validator and any
 * future admin UI read from: the operators each type accepts and the shape its
 * value must take, in ONE place. Operators are NOT interchangeable across types.
 *
 * DECLARATION ORDER IS EVALUATION COST. Types are declared and returned
 * cheap-first: checks satisfied from data already loaded for the order (roles,
 * cart contents, the order's currency / country / gateway) come before the
 * three that each need their own aggregate query — `lifetime_points`,
 * `order_count`, `lifetime_spend`. A future evaluator iterates this order and
 * short-circuits on the first failed condition without having to sort anything.
 */
final class CampaignConditionType {

    /*
     * Operators. Shared spelling; each type accepts only a subset — see schema().
     */
    public const OP_IN      = 'in';
    public const OP_NOT_IN  = 'not_in';
    public const OP_GTE     = 'gte';
    public const OP_LTE     = 'lte';
    public const OP_GT      = 'gt';
    public const OP_LT      = 'lt';
    public const OP_EQ      = 'eq';
    public const OP_BETWEEN = 'between';

    /* --- cheap: satisfied from data already in memory for the order --- */

    public const USER_ROLE        = 'user_role';
    public const CUSTOMER_IDS     = 'customer_ids';
    public const CURRENCY         = 'currency';
    public const PAYMENT_METHOD   = 'payment_method';
    public const COUNTRY          = 'country';
    public const PRODUCT_IN_CART  = 'product_in_cart';
    public const CATEGORY_IN_CART = 'category_in_cart';

    /**
     * tier — the tier system does not exist yet; its schema is a later task.
     *
     * The constant and its operator set are defined now so the vocabulary is
     * complete and a future UI has one source, but tier is deliberately left
     * out of all(), so nothing here lets a merchant create a tier condition
     * today. is_known() still recognises it, so a stored row is never treated
     * as corrupt.
     *
     * `gte` / `lte` on tier are ORDINAL comparisons ("Silver or above"), which
     * a slug cannot express — so the future `yayloyalty_tiers` table needs an
     * integer `level` column to compare against.
     */
    public const TIER = 'tier';

    /* --- expensive: each needs an aggregate query per evaluation --- */

    public const LIFETIME_POINTS = 'lifetime_points';
    public const ORDER_COUNT     = 'order_count';
    public const LIFETIME_SPEND  = 'lifetime_spend';

    /**
     * Types recognised by the schema but not yet offered to merchants. Excluded
     * from all() so they cannot be created; kept in all_known() so stored rows
     * round-trip.
     *
     * @var string[]
     */
    public const UNAVAILABLE = [
        self::TIER,
    ];

    /**
     * Per-type rules, in evaluation-cost order.
     *
     *   operators     string[]  operators this type accepts
     *   value         string    expected value shape:
     *                             'string_list'   — non-empty list of strings
     *                             'int_list'      — non-empty list of integer ids
     *                             'currency_list' — non-empty list of ISO 4217 codes
     *                             'country_list'  — { countries: ISO 3166-1 alpha-2[], source }
     *                             'int'           — an integer; with `between`, a [low, high] pair
     *                             'money'         — { amount, currency }; amount with `between` is [low, high]
     *                             'tier'          — slug, or list for in/not_in (gated — see TIER)
     *   max           int       cap on list length (customer_ids)
     *   require_keys  string[]  sub-keys the value object must carry
     *   source_values string[]  allowed values for the country `source` sub-key
     *   normalize     string[]  transforms applied on save: array | int | uppercase | lowercase | dedupe
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array {
        return [
            self::USER_ROLE        => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'string_list',
                'normalize' => [ 'array', 'lowercase', 'dedupe' ],
            ],
            self::CUSTOMER_IDS     => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'int_list',
                'max'       => 100,
                'normalize' => [ 'array', 'int', 'dedupe' ],
            ],
            self::CURRENCY         => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'currency_list',
                'normalize' => [ 'array', 'uppercase', 'dedupe' ],
            ],
            self::PAYMENT_METHOD   => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'string_list',
                'normalize' => [ 'array', 'dedupe' ],
            ],
            self::COUNTRY          => [
                'operators'     => [ self::OP_IN, self::OP_NOT_IN ],
                'value'         => 'country_list',
                'require_keys'  => [ 'source' ],
                'source_values' => [ 'shipping', 'billing', 'any' ],
                'normalize'     => [ 'array', 'uppercase', 'dedupe' ],
            ],
            self::PRODUCT_IN_CART  => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'int_list',
                'normalize' => [ 'array', 'int', 'dedupe' ],
            ],
            self::CATEGORY_IN_CART => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'int_list',
                'normalize' => [ 'array', 'int', 'dedupe' ],
            ],
            self::TIER             => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN, self::OP_GTE, self::OP_LTE ],
                'value'     => 'tier',
                'normalize' => [ 'dedupe' ],
            ],
            self::LIFETIME_POINTS  => [
                'operators' => [ self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ, self::OP_BETWEEN ],
                'value'     => 'int',
            ],
            self::ORDER_COUNT      => [
                'operators' => [ self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ, self::OP_BETWEEN ],
                'value'     => 'int',
            ],
            self::LIFETIME_SPEND   => [
                'operators'    => [ self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ, self::OP_BETWEEN ],
                'value'        => 'money',
                'require_keys' => [ 'currency' ],
            ],
        ];
    }

    /**
     * Types a merchant can use today, in evaluation-cost order.
     *
     * @return string[]
     */
    public static function all(): array {
        return array_values( array_diff( array_keys( self::schema() ), self::UNAVAILABLE ) );
    }

    /**
     * Every type the schema recognises, `tier` included. For reading stored
     * rows, never for offering choices.
     *
     * @return string[]
     */
    public static function all_known(): array {
        return array_keys( self::schema() );
    }

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all(), true );
    }

    public static function is_known( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all_known(), true );
    }

    /**
     * Operators a type accepts, or [] for an unknown type.
     *
     * @return string[]
     */
    public static function operators_for( string $type ): array {
        $operators = self::schema()[ $type ]['operators'] ?? [];

        return is_array( $operators ) ? $operators : [];
    }

    /**
     * @throws \InvalidArgumentException When $value is not a type a merchant can use.
     */
    public static function assert( $value ): string {
        if ( ! self::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown condition type "%s". Expected one of: %s.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', self::all() )
                )
            );
        }

        return $value;
    }
}
