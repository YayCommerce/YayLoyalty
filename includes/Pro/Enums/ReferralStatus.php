<?php
namespace YayLoyalty\Pro\Enums;

use YayLoyalty\Enums\StringEnumMethods;

defined( 'ABSPATH' ) || exit;

/** A referral's state: pending → on_hold → rewarded | reversed, or rejected / expired. */
final class ReferralStatus {

    use StringEnumMethods;

    /** The friend signed up and has no qualifying order yet. */
    public const PENDING = 'pending';

    /** The friend's first order completed and qualified; the payout waits for `hold_until`. */
    public const ON_HOLD = 'on_hold';

    /** The advocate was paid. */
    public const REWARDED = 'rewarded';

    /** Never pays; `reject_reason` says why. */
    public const REJECTED = 'rejected';

    /** Paid, then clawed back in full. */
    public const REVERSED = 'reversed';

    /** No qualifying order before the friend's coupon expired. */
    public const EXPIRED = 'expired';

    /** @return string[] */
    public static function all(): array {
        return [ self::PENDING, self::ON_HOLD, self::REWARDED, self::REJECTED, self::REVERSED, self::EXPIRED ];
    }

    protected static function noun(): string {
        return 'referral status';
    }
}
