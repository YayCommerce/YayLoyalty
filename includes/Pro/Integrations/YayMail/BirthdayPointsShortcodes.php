<?php
namespace YayLoyalty\Pro\Integrations\YayMail;

use YayLoyalty\Integrations\YayMail\LoyaltyEmailShortcodes;
use YayLoyalty\Integrations\YayMail\LoyaltyYayMailEmail;
use YayLoyalty\Pro\Emails\BirthdayPointsEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Birthday points email's YayMail shortcodes, from BirthdayPointsEmail's template values.
 *
 * @method static BirthdayPointsShortcodes get_instance()
 */
class BirthdayPointsShortcodes extends LoyaltyEmailShortcodes {

    use SingletonTrait;

    protected const VALUE_KEYS = [
        'birthday_message' => 'birthday_text',
        'balance_points'   => 'balance_text',
    ];

    protected const HTML_KEYS = [ 'birthday_message' ];

    public $available_email_ids = [ BirthdayPointsEmail::ID ];

    protected function yaymail_email(): LoyaltyYayMailEmail {
        return BirthdayPointsYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'birthday_message' => __( 'Birthday Points Message', 'yayloyalty' ),
            'points'           => __( 'Birthday Points', 'yayloyalty' ),
        ] + self::shared_descriptions( 'points_label', 'balance', 'balance_points', 'balance_value', 'balance_value_text' );
    }
}
