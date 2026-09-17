<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The vocabulary for a campaign's `conditions`: constants plus a per-type
 * schema() the validator reads. Declaration order is evaluation cost,
 * cheap-first, so the evaluator short-circuits without sorting. Shared
 * accessors live in ConditionTypeMethods.
 */
final class CampaignConditionType {

    use ConditionTypeMethods;

    // Each type accepts only a subset; see schema().
    public const OP_IN      = 'in';
    public const OP_NOT_IN  = 'not_in';
    public const OP_GTE     = 'gte';
    public const OP_LTE     = 'lte';
    public const OP_GT      = 'gt';
    public const OP_LT      = 'lt';
    public const OP_EQ      = 'eq';
    public const OP_BETWEEN = 'between';

    // Cheap: satisfied from data already loaded for the order.

    public const USER_ROLE        = 'user_role';
    public const CUSTOMER_IDS     = 'customer_ids';
    public const CURRENCY         = 'currency';
    public const PAYMENT_METHOD   = 'payment_method';
    public const COUNTRY          = 'country';
    public const PRODUCT_IN_CART  = 'product_in_cart';
    public const CATEGORY_IN_CART = 'category_in_cart';

    /**
     * Defined ahead of the tier system; excluded from all() (see UNAVAILABLE).
     * gte/lte are ordinal ("Silver or above"), so tiers will need a rank column.
     */
    public const TIER = 'tier';

    // Expensive: each needs an aggregate query per evaluation.

    public const LIFETIME_POINTS = 'lifetime_points';
    public const ORDER_COUNT     = 'order_count';
    public const LIFETIME_SPEND  = 'lifetime_spend';

    /**
     * Recognised but not yet offered: excluded from all(), kept in all_known() so stored rows round-trip.
     *
     * @var string[]
     */
    public const UNAVAILABLE = [
        self::TIER,
    ];

    /**
     * Per-type rules, in evaluation-cost order: operators, value shape
     * (string_list | int_list | currency_list | country_list | int | money |
     * tier; `between` takes a [low, high] pair), max list length,
     * source_values, and the normalize transforms applied on save.
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
}
