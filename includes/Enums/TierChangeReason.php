<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Why a customer's tier changed: the `reason` of a `tier_changed` activity row and of the
 * `yayloyalty_tier_changed` action. Lite keeps the vocabulary so a downgraded site still reads stored rows.
 */
final class TierChangeReason {

    use StringEnumMethods;

    /** A ledger EARN row raised the points earned. */
    public const EARN = 'earn';

    /** A REFUND_EARN row moved the points earned. */
    public const REFUND = 'refund';

    /** A manual ADJUST row moved the points earned. */
    public const ADJUSTMENT = 'adjustment';

    /** Staff assigned a manual tier floor. */
    public const MANUAL = 'manual';

    /** A manual tier floor ended or staff removed it. */
    public const MANUAL_END = 'manual_end';

    /** A tier was added or its threshold changed. */
    public const THRESHOLD_CHANGE = 'threshold_change';

    /** The customer's tier, or their floor's tier, was deleted. */
    public const TIER_DELETED = 'tier_deleted';

    /** Tiers were turned on and every customer was placed. */
    public const PLACEMENT = 'placement';

    /** A rolling-mode hold ended, written by the daily hold-end step. */
    public const HOLD_END = 'hold_end';

    /** Tier qualification switched from rolling to lifetime. */
    public const MODE_CHANGE = 'mode_change';

    /** @return string[] */
    public static function all(): array {
        return [
            self::EARN,
            self::REFUND,
            self::ADJUSTMENT,
            self::MANUAL,
            self::MANUAL_END,
            self::THRESHOLD_CHANGE,
            self::TIER_DELETED,
            self::PLACEMENT,
            self::HOLD_END,
            self::MODE_CHANGE,
        ];
    }

    protected static function noun(): string {
        return 'tier change reason';
    }
}
