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
        'earned_points'  => 'earned_text',
        'balance_points' => 'balance_text',
        'points_url'     => 'cta_url',
    ];

    protected const HTML_KEYS = [ 'earned_points', 'balance_value', 'balance_value_text' ];

    public $available_email_ids = [ PointsEarnedEmail::ID ];

    protected function yaymail_email(): LoyaltyYayMailEmail {
        return PointsEarnedYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'earned_points'      => __( 'Points Earned Message', 'yayloyalty' ),
            'points'             => __( 'Points Earned', 'yayloyalty' ),
            'points_label'       => __( 'Points Label', 'yayloyalty' ),
            'balance'            => __( 'Balance', 'yayloyalty' ),
            'balance_points'     => __( 'Balance With Label', 'yayloyalty' ),
            'balance_value'      => __( 'Balance Value', 'yayloyalty' ),
            'balance_value_text' => __( 'Balance Value Message', 'yayloyalty' ),
            'order_number'       => __( 'Order Number', 'yayloyalty' ),
            'points_url'         => __( 'Points Page URL', 'yayloyalty' ),
        ];
    }
}
