<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The vocabulary for a campaign's `conditions`, with a per-type schema() that the validator and the evaluator read.
 * Declaration order is the order conditions are stored in.
 */
final class CampaignConditionType {

    use ConditionTypeMethods;

    // Each type accepts only a subset, listed in schema().
    public const OP_IN      = 'in';
    public const OP_NOT_IN  = 'not_in';
    public const OP_GTE     = 'gte';
    public const OP_LTE     = 'lte';
    public const OP_GT      = 'gt';
    public const OP_LT      = 'lt';
    public const OP_EQ      = 'eq';
    public const OP_BETWEEN = 'between';

    // What a condition is compared against: the customer, their CustomerFacts, the lines being bought (an order's or a shopper's), or the order being scored.
    public const INPUT_CUSTOMER = 'customer';
    public const INPUT_FACTS    = 'facts';
    public const INPUT_BASKET   = 'basket';
    public const INPUT_ORDER    = 'order';

    // Which address a `country` condition reads. ANY matches either.
    public const SOURCE_SHIPPING = 'shipping';
    public const SOURCE_BILLING  = 'billing';
    public const SOURCE_ANY      = 'any';

    // About the customer or the order.

    public const USER_ROLE        = 'user_role';
    public const CUSTOMER_IDS     = 'customer_ids';
    public const CURRENCY         = 'currency';
    public const PAYMENT_METHOD   = 'payment_method';
    public const COUNTRY          = 'country';
    public const PRODUCT_IN_CART  = 'product_in_cart';
    public const CATEGORY_IN_CART = 'category_in_cart';

    /**
     * Defined ahead of the tier system, so UNAVAILABLE keeps it out of all().
     * gte/lte are ordinal ("Silver or above"), so tiers will need a rank column.
     */
    public const TIER = 'tier';

    // About the customer's history: read once per evaluation by CustomerFactsReader.

    public const LIFETIME_POINTS = 'lifetime_points';
    public const ORDER_COUNT     = 'order_count';
    public const LIFETIME_SPEND  = 'lifetime_spend';

    /**
     * Recognised but not offered yet: all() leaves them out, and all_known() keeps them for stored rows.
     *
     * @var string[]
     */
    public const UNAVAILABLE = [
        self::TIER,
    ];

    /**
     * Per-type rules: operators, value shape, max list length, source_values, the normalize transforms
     * applied on save, and the evaluator's input (INPUT_*). The value shapes are string_list, int_list,
     * currency_list, country_list, int, money and tier. `between` takes a [low, high] pair.
     * Tier has no input because it cannot be evaluated yet.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array {
        return [
            self::USER_ROLE        => [
                'input'     => self::INPUT_CUSTOMER,
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'string_list',
                'normalize' => [ 'array', 'lowercase', 'dedupe' ],
            ],
            self::CUSTOMER_IDS     => [
                'input'     => self::INPUT_CUSTOMER,
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'int_list',
                'max'       => 100,
                'normalize' => [ 'array', 'int', 'dedupe' ],
            ],
            self::CURRENCY         => [
                'input'     => self::INPUT_BASKET,
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'currency_list',
                'normalize' => [ 'array', 'uppercase', 'dedupe' ],
            ],
            self::PAYMENT_METHOD   => [
                'input'     => self::INPUT_ORDER,
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'string_list',
                'normalize' => [ 'array', 'dedupe' ],
            ],
            self::COUNTRY          => [
                'input'         => self::INPUT_ORDER,
                'operators'     => [ self::OP_IN, self::OP_NOT_IN ],
                'value'         => 'country_list',
                'require_keys'  => [ 'source' ],
                'source_values' => [ self::SOURCE_SHIPPING, self::SOURCE_BILLING, self::SOURCE_ANY ],
                'normalize'     => [ 'array', 'uppercase', 'dedupe' ],
            ],
            self::PRODUCT_IN_CART  => [
                'input'     => self::INPUT_BASKET,
                'operators' => [ self::OP_IN, self::OP_NOT_IN ],
                'value'     => 'int_list',
                'normalize' => [ 'array', 'int', 'dedupe' ],
            ],
            self::CATEGORY_IN_CART => [
                'input'     => self::INPUT_BASKET,
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
                'input'     => self::INPUT_FACTS,
                'operators' => [ self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ, self::OP_BETWEEN ],
                'value'     => 'int',
            ],
            self::ORDER_COUNT      => [
                'input'     => self::INPUT_FACTS,
                'operators' => [ self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ, self::OP_BETWEEN ],
                'value'     => 'int',
            ],
            self::LIFETIME_SPEND   => [
                'input'        => self::INPUT_FACTS,
                'operators'    => [ self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ, self::OP_BETWEEN ],
                'value'        => 'money',
                'require_keys' => [ 'currency' ],
            ],
        ];
    }

    /** The INPUT_* a condition type is compared against, or null for a type the evaluator cannot evaluate. */
    public static function input_for( string $type ): ?string {
        return self::schema()[ $type ]['input'] ?? null;
    }
}
