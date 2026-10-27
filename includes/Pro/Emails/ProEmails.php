<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Emails\Emails;

defined( 'ABSPATH' ) || exit;

/** Registers the Pro loyalty emails and their groups with WooCommerce. */
final class ProEmails {

    public const GROUP_REWARDS  = 'yayloyalty_rewards';
    public const GROUP_BIRTHDAY = 'yayloyalty_birthday';

    /**
     * The email action an order's redemption is relayed on, for the Reward claimed email. Its only argument is the
     * REDEEM ledger row id. Held here, not on the email: WooCommerce reads it on init, before WC_Email loads.
     */
    public const REWARD_CLAIMED_ACTION = 'yayloyalty_reward_claimed_email';

    /**
     * Adds the Pro loyalty emails to WooCommerce's mailer.
     *
     * @param array<string, \WC_Email> $classes
     * @return array<string, \WC_Email>
     */
    public static function add_classes( array $classes ): array {
        return Emails::add(
            $classes,
            new RewardClaimedEmail(),
            new BirthdayPointsEmail()
        );
    }

    /**
     * WooCommerce builds the mailer before it fires {action}_notification, so the email's listener exists.
     * No email class is named here: WooCommerce reads this list on init, before WC_Email loads.
     *
     * @param string[] $actions
     * @return string[]
     */
    public static function add_email_actions( array $actions ): array {
        $actions[] = self::REWARD_CLAIMED_ACTION;

        return $actions;
    }

    /**
     * Passes an order's redemption to the Reward claimed email as its REDEEM row id: only scalars cross WooCommerce's
     * deferred queue. Hooked from boot(), not by the email, because the mailer may not exist yet when it fires.
     *
     * @param Outcome $outcome The redemption; its entry is the last REDEEM row, with the balance after every line and
     *                         the order as its reference.
     */
    public static function relay_redemption( Outcome $outcome ): void {
        $entry = $outcome->get_entry();

        if ( ! $entry || null === $entry->get_id() ) {
            return;
        }

        /**
         * Fires once per order whose redemption committed, for the Reward claimed email. WooCommerce relays it to
         * {action}_notification, at once or at the end of the request.
         *
         * @param int $entry_id The order's last REDEEM ledger row.
         */
        do_action( self::REWARD_CLAIMED_ACTION, $entry->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
    }

    /**
     * @param array<string, string> $groups
     * @return array<string, string>
     */
    public static function add_groups( array $groups ): array {
        $groups[ self::GROUP_REWARDS ]  = __( 'Loyalty: Rewards', 'yayloyalty' );
        $groups[ self::GROUP_BIRTHDAY ] = __( 'Loyalty: Birthday', 'yayloyalty' );

        return $groups;
    }
}
