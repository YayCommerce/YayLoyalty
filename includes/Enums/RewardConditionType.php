<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The vocabulary for a reward's `conditions` — the eligibility rules ANDed
 * together to decide whether a reward is available to a given customer.
 *
 * Same shape as `CampaignConditionType` — a final class of constants plus a
 * per-type map (schema()), the single source the save-time validator (and any
 * future admin UI) reads from — but its own file and its own vocabulary:
 * rewards and campaigns are independent features and this is expected to
 * diverge from the campaign side as each grows its own types. Four of the
 * five types here happen to use exactly the same value shapes as their
 * campaign counterparts today; that shape-level logic is shared via
 * `Utils\ConditionValueRules`, not by sharing this enum.
 *
 * `all()` / `all_known()` / `is_valid()` / `is_known()` / `operators_for()` /
 * `assert()` are ConditionTypeMethods — identical to CampaignConditionType's,
 * so that logic lives once. This class supplies only what actually differs:
 * the vocabulary itself (the constants and schema() below) and UNAVAILABLE.
 */
final class RewardConditionType {

    use ConditionTypeMethods;

    /*
     * Operators. Shared spelling with CampaignConditionType, but declared
     * again here rather than imported from it — these are stable literal
     * strings, not logic, and importing them would tie this vocabulary's file
     * to campaigns' for no benefit.
     */
    public const OP_IN      = 'in';
    public const OP_NOT_IN  = 'not_in';
    public const OP_GTE     = 'gte';
    public const OP_LTE     = 'lte';
    public const OP_GT      = 'gt';
    public const OP_LT      = 'lt';
    public const OP_EQ      = 'eq';
    public const OP_BETWEEN = 'between';

    /* --- cheap: satisfied from data already in memory for the customer --- */

    public const USER_ROLE    = 'user_role';
    public const CUSTOMER_IDS = 'customer_ids';

    /**
     * tier — the tier system does not exist yet; its schema is a later task.
     *
     * Defined now, same reasoning as CampaignConditionType::TIER, so the
     * vocabulary is complete and a future UI has one source: the constant and
     * its operators exist, but TIER is excluded from all() so nothing here
     * lets a merchant create a tier condition today, while is_known() still
     * recognises it so a stored row is never treated as corrupt. `gte`/`lte`
     * are ordinal ("Silver or above"), same as campaigns' tier condition.
     */
    public const TIER = 'tier';

    /* --- needs its own read beyond what's already loaded --- */

    public const LIFETIME_POINTS = 'lifetime_points';

    /**
     * redemption_count — how many times this customer has already redeemed
     * this reward. The most expensive type here: everything else above reads
     * an attribute already available for the customer being evaluated;
     * this needs a COUNT over `yayloyalty_redemptions` filtered to
     * (reward_id, user_id) — see docs/data-model.md for the index that query
     * needs, on a table this schema does not create.
     */
    public const REDEMPTION_COUNT = 'redemption_count';

    /**
     * Types recognised by the schema but not yet offered to merchants.
     * Excluded from all() so they cannot be created; kept in all_known() so
     * stored rows round-trip.
     *
     * @var string[]
     */
    public const UNAVAILABLE = [
        self::TIER,
    ];

    /**
     * Per-type rules, in evaluation-cost order. Same field meanings as
     * CampaignConditionType::schema() — see that class for the full list;
     * this vocabulary only uses `operators`, `value` (`string_list`,
     * `int_list`, `int`, `tier`), `max`, `min` (an inclusive floor on an
     * `int` value — only `redemption_count` uses it, to reject a negative
     * count) and `normalize`.
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
