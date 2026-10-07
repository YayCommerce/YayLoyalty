<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The `meta_key` vocabulary of `yayloyalty_customer_meta`, which is also the activity log's `event_type`.
 * Today CustomerActivityService writes only `membership_started`, `points_adjusted` and `account_deleted`.
 * A customer whose only history is one of the other five has zero activity rows. That count is correct, not a bug.
 */
final class ActivityEvent {

    public const MEMBERSHIP_STARTED       = 'membership_started';
    public const POINTS_ADJUSTED          = 'points_adjusted';
    public const TIER_CHANGED             = 'tier_changed';
    public const EXPIRY_RESET             = 'expiry_reset';
    public const REWARD_REDEEMED          = 'reward_redeemed';
    public const BALANCE_EMAIL_SENT       = 'balance_email_sent';
    public const EXPIRING_SOON_EMAIL_SENT = 'expiring_soon_email_sent';
    public const EARNING_LIMITED          = 'earning_limited';
    public const ACCOUNT_DELETED          = 'account_deleted';

    /**
     * In display order, `account_deleted` last.
     *
     * @var string[]
     */
    public const ALL = [
        self::MEMBERSHIP_STARTED,
        self::POINTS_ADJUSTED,
        self::TIER_CHANGED,
        self::EXPIRY_RESET,
        self::REWARD_REDEEMED,
        self::BALANCE_EMAIL_SENT,
        self::EXPIRING_SOON_EMAIL_SENT,
        self::EARNING_LIMITED,
        self::ACCOUNT_DELETED,
    ];
}
