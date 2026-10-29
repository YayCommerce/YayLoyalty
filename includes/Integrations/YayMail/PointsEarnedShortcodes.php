<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points earned email's YayMail shortcodes, from PointsEarnedEmail's template values.
 *
 * @method static PointsEarnedShortcodes get_instance()
 */
class PointsEarnedShortcodes extends LoyaltyEmailShortcodes {

    use SingletonTrait;

    protected const VALUE_KEYS = [
        'earned_message' => 'earned_text',
        'balance_points' => 'balance_text',
        'points_url'     => 'cta_url',
    ];

    protected const HTML_KEYS = [ 'earned_message' ];

    public $available_email_ids = [ PointsEarnedEmail::ID ];

    protected function yaymail_email(): LoyaltyYayMailEmail {
        return PointsEarnedYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'earned_message' => __( 'Points Earned Message', 'yayloyalty' ),
            'points'         => __( 'Points Earned', 'yayloyalty' ),
        ] + self::shared_descriptions( 'points_label', 'balance', 'balance_points', 'balance_value', 'balance_value_text', 'order_number' ) + [
            'points_url' => __( 'Points Page URL', 'yayloyalty' ),
        ];
    }
}
