<?php
namespace YayLoyalty\Pro\Enums;

use YayLoyalty\Enums\StringEnumMethods;

defined( 'ABSPATH' ) || exit;

/** Why a referral was rejected: the admin's reasons from the reject dialog, then the ones the plugin sets itself. */
final class ReferralRejectReason {

    use StringEnumMethods;

    public const DUPLICATE_ACCOUNT = 'duplicate_account';

    public const ORDER_REFUNDED = 'order_refunded';

    public const NOT_GENUINE = 'not_genuine';

    public const OTHER = 'other';

    /** The first order's billing email is the advocate's. */
    public const SELF_REFERRAL = 'self_referral';

    /** The first order was under the minimum, or used another coupon while that is excluded. */
    public const NOT_QUALIFYING = 'not_qualifying';

    public const REFUNDED_DURING_HOLD = 'refunded_during_hold';

    /** @return string[] */
    public static function all(): array {
        return [ self::DUPLICATE_ACCOUNT, self::ORDER_REFUNDED, self::NOT_GENUINE, self::OTHER, self::SELF_REFERRAL, self::NOT_QUALIFYING, self::REFUNDED_DURING_HOLD ];
    }

    protected static function noun(): string {
        return 'referral reject reason';
    }
}
