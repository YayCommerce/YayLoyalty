<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The vocabulary for a reward's `conditions`. Same shape as
 * CampaignConditionType but its own vocabulary, free to diverge; value
 * shapes are shared through Utils\ConditionValueRules, not this enum.
 * Shared accessors live in ConditionTypeMethods.
 */
final class RewardConditionType {

    use ConditionTypeMethods;

    // Declared again rather than imported: literal strings, and importing would tie this file to campaigns.
    public const OP_IN      = 'in';
    public const OP_NOT_IN  = 'not_in';
    public const OP_GTE     = 'gte';
    public const OP_LTE     = 'lte';
    public const OP_GT      = 'gt';
    public const OP_LT      = 'lt';
    public const OP_EQ      = 'eq';
    public const OP_BETWEEN = 'between';

    // Cheap: satisfied from data already loaded for the customer.

    public const USER_ROLE    = 'user_role';
    public const CUSTOMER_IDS = 'customer_ids';

    /** Defined ahead of the tier system; excluded from all() (see UNAVAILABLE). gte/lte are ordinal. */
    public const TIER = 'tier';

    // Needs its own read beyond what is already loaded.

    public const LIFETIME_POINTS = 'lifetime_points';

    /** Times this customer has redeemed this reward; needs a COUNT over yayloyalty_redemptions. */
    public const REDEMPTION_COUNT = 'redemption_count';

    /**
     * Recognised but not yet offered: excluded from all(), kept in all_known() so stored rows round-trip.
     *
     * @var string[]
     */
    public const UNAVAILABLE = [
        self::TIER,
    ];

    /**
     * Per-type rules, in evaluation-cost order; same fields as
     * CampaignConditionType::schema(), plus `min` (an inclusive floor).
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
            self::TIER             => [
                'operators' => [ self::OP_IN, self::OP_NOT_IN, self::OP_GTE, self::OP_LTE ],
                'value'     => 'tier',
                'normalize' => [ 'dedupe' ],
            ],
            self::LIFETIME_POINTS  => [
                'operators' => [ self::OP_GTE, self::OP_LTE, self::OP_GT, self::OP_LT, self::OP_EQ, self::OP_BETWEEN ],
                'value'     => 'int',
            ],
            self::REDEMPTION_COUNT => [
                'operators' => [ self::OP_LT, self::OP_LTE, self::OP_EQ ],
                'value'     => 'int',
                'min'       => 0,
            ],
        ];
    }
}
