<?php
namespace YayLoyalty\Pro\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Integrations\YayMail\LoyaltyEmailShortcodes;
use YayLoyalty\Integrations\YayMail\LoyaltyYayMailEmail;
use YayLoyalty\Pro\Emails\RewardClaimedEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Reward claimed email in YayMail.
 *
 * @method static RewardClaimedYayMailEmail get_instance()
 */
class RewardClaimedYayMailEmail extends LoyaltyYayMailEmail {

    use SingletonTrait;

    protected function email_id(): string {
        return RewardClaimedEmail::ID;
    }

    /**
     * Its sentences come from LoyaltyEmail, as the `yayloyalty-reward-claimed.php` templates' do, and from
     * RewardClaimedEmail's template values. The lines are a table, so they sit in a <div>, not a <p>.
     */
    protected function default_body(): string {
        $greeting = LoyaltyEmail::greeting_text( LoyaltyEmailShortcodes::tag( 'customer_name' ) );
        $balance  = LoyaltyEmail::balance_sentence( LoyaltyEmailShortcodes::tag( 'balance_points' ) );

        return '<p>' . $greeting . '</p><p>' . LoyaltyEmailShortcodes::tag( 'claimed_points' ) . '</p><div>' . LoyaltyEmailShortcodes::tag( 'reward_lines' ) . '</div><p>' . $balance . '</p>';
    }
}
