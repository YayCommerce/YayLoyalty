<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points expiring soon email's YayMail shortcodes, from PointsExpiringSoonEmail's template values.
 *
 * @method static PointsExpiringSoonShortcodes get_instance()
 */
class PointsExpiringSoonShortcodes extends LoyaltyEmailShortcodes {

    use SingletonTrait;

    protected const VALUE_KEYS = [
        'expiring_points' => 'expiring_text',
        'points_url'      => 'cta_url',
    ];

    protected const HTML_KEYS = [ 'expiring_points', 'points_value', 'points_value_text' ];

    public $available_email_ids = [ PointsExpiringSoonEmail::ID ];

    protected function yaymail_email(): LoyaltyYayMailEmail {
        return PointsExpiringSoonYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'expiring_points' => __( 'Points Expiring Message', 'yayloyalty' ),
            'points'          => __( 'Points Expiring', 'yayloyalty' ),
        ] + self::shared_descriptions( 'points_label' ) + [
            'expiry_date'       => __( 'Expiry Date', 'yayloyalty' ),
            'points_value'      => __( 'Points Value', 'yayloyalty' ),
            'points_value_text' => __( 'Points Value Message', 'yayloyalty' ),
            'points_url'        => __( 'Points Page URL', 'yayloyalty' ),
        ];
    }
}
