<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points earned email in YayMail.
 *
 * @method static PointsEarnedYayMailEmail get_instance()
 */
class PointsEarnedYayMailEmail extends LoyaltyYayMailEmail {

    use SingletonTrait;

    protected function email_id(): string {
        return PointsEarnedEmail::ID;
    }

    /**
     * Its sentences come from LoyaltyEmail, as the `yayloyalty-points-earned.php` templates' do, and from
     * PointsEarnedEmail's template values. Unlike the templates, it prints the greeting even when the
     * customer's name is ''. It has no "View your points" button: the link is '' unless a site filters
     * it in, and a static button cannot hide itself.
     */
    protected function default_body(): string {
        $greeting = LoyaltyEmail::greeting_text( LoyaltyEmailShortcodes::tag( 'customer_name' ) );
        $balance  = LoyaltyEmail::balance_sentence( LoyaltyEmailShortcodes::tag( 'balance_points' ) );

        return '<p>' . $greeting . '</p><p>' . LoyaltyEmailShortcodes::tag( 'earned_points' ) . '</p><p>' . $balance . '</p><p>' . LoyaltyEmailShortcodes::tag( 'balance_value_text' ) . '</p>';
    }
}
