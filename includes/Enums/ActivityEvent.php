<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * The `meta_key` vocabulary of `yayloyalty_customer_meta`, which doubles as
 * the activity log's `event_type`. Only four of the nine have a writer
 * today: `account_created`, `points_adjusted` (on `yayloyalty_ledger_recorded`),
 * `earning_limited` (on `yayloyalty_award_limited`) and `account_deleted`
 * (on `delete_user`/`wpmu_delete_user`), all written by
 * Services\CustomerActivityService via Engine\CustomerActivityListener. The other five have no source feature yet
 * (tiers, expiry job, redemptions, balance/expiry emails) — a customer
 * whose only history would be one of them legitimately has zero activity
 * rows; that is the true count, not a bug.
 */
final class ActivityEvent {

    public const ACCOUNT_CREATED          = 'account_created';
    public const POINTS_ADJUSTED          = 'points_adjusted';
    public const TIER_CHANGED             = 'tier_changed';
    public const EXPIRY_RESET             = 'expiry_reset';
    public const REWARD_REDEEMED          = 'reward_redeemed';
    public const BALANCE_EMAIL_SENT       = 'balance_email_sent';
    public const EXPIRING_SOON_EMAIL_SENT = 'expiring_soon_email_sent';
    public const EARNING_LIMITED          = 'earning_limited';
    public const ACCOUNT_DELETED          = 'account_deleted';

    /**
     * Artboard order — `account_deleted` last.
     *
     * @var string[]
     */
    public const ALL = [
        self::ACCOUNT_CREATED,
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
