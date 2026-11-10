<?php
namespace YayLoyalty\Pro\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Integrations\YayMail\LoyaltyEmailShortcodes;
use YayLoyalty\Integrations\YayMail\LoyaltyYayMailEmail;
use YayLoyalty\Pro\Emails\BirthdayPointsEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Birthday points email in YayMail.
 *
 * @method static BirthdayPointsYayMailEmail get_instance()
 */
class BirthdayPointsYayMailEmail extends LoyaltyYayMailEmail {

    use SingletonTrait;

    protected function email_id(): string {
        return BirthdayPointsEmail::ID;
    }

    /**
     * Its sentences come from LoyaltyEmail, as the `yayloyalty-birthday-points.php` templates' do, and from
     * BirthdayPointsEmail's template values. Unlike the templates, it prints the greeting even when the
     * customer's name is ''.
     */
    protected function default_body(): string {
        $greeting = LoyaltyEmail::greeting_text( LoyaltyEmailShortcodes::tag( 'customer_name' ) );
        $balance  = LoyaltyEmail::balance_sentence( LoyaltyEmailShortcodes::tag( 'balance_points' ) );

        return '<p>' . $greeting . '</p><p>' . LoyaltyEmailShortcodes::tag( 'birthday_message' ) . '</p><p>' . $balance . '</p><p>' . LoyaltyEmailShortcodes::tag( 'balance_value_text' ) . '</p>';
    }
}
