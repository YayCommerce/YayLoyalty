<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Emails\Emails;

defined( 'ABSPATH' ) || exit;

/** Registers the Pro loyalty emails and their groups with WooCommerce. */
final class ProEmails {

    public const GROUP_REWARDS  = 'yayloyalty_rewards';
    public const GROUP_REFERRAL = 'yayloyalty_referral';
    public const GROUP_TIERS    = 'yayloyalty_tiers';
    public const GROUP_BIRTHDAY = 'yayloyalty_birthday';

    /**
     * Adds the Pro loyalty emails to WooCommerce's mailer.
     *
     * @param array<string, \WC_Email> $classes
     * @return array<string, \WC_Email>
     */
    public static function add_classes( array $classes ): array {
        return Emails::add(
            $classes,
            new ExpiringFinalReminderEmail(),
            new RewardClaimedEmail(),
            new CouponIssuedEmail(),
            new InviteSentEmail(),
            new ReferralRewardEarnedEmail(),
            new TierReachedEmail(),
            new TierEndingSoonEmail(),
            new TierLostEmail(),
            new BirthdayPointsEmail()
        );
    }

    /**
     * @param array<string, string> $groups
     * @return array<string, string>
     */
    public static function add_groups( array $groups ): array {
        $groups[ self::GROUP_REWARDS ]  = __( 'Loyalty: Rewards', 'yayloyalty' );
        $groups[ self::GROUP_REFERRAL ] = __( 'Loyalty: Referral', 'yayloyalty' );
        $groups[ self::GROUP_TIERS ]    = __( 'Loyalty: Tiers', 'yayloyalty' );
        $groups[ self::GROUP_BIRTHDAY ] = __( 'Loyalty: Birthday', 'yayloyalty' );

        return $groups;
    }
}
