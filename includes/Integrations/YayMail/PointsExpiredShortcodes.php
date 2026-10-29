<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\PointsExpiredEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points expired email's YayMail shortcodes, from PointsExpiredEmail's template values.
 *
 * @method static PointsExpiredShortcodes get_instance()
 */
class PointsExpiredShortcodes extends LoyaltyEmailShortcodes {

    use SingletonTrait;

    protected const VALUE_KEYS = [
        'expired_points' => 'expired_text',
        'balance_points' => 'balance_text',
    ];

    protected const HTML_KEYS = [ 'expired_points' ];

    public $available_email_ids = [ PointsExpiredEmail::ID ];

    protected function yaymail_email(): LoyaltyYayMailEmail {
        return PointsExpiredYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'expired_points' => __( 'Points Expired Message', 'yayloyalty' ),
            'points'         => __( 'Points Expired', 'yayloyalty' ),
        ] + self::shared_descriptions( 'points_label', 'balance', 'balance_points', 'balance_value', 'balance_value_text' );
    }
}
