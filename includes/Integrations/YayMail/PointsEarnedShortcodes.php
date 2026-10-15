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
            'earned_points'      => __( 'Sentence saying what was earned and why', 'yayloyalty' ),
            'points'             => __( 'Points earned', 'yayloyalty' ),
            'points_label'       => __( 'Points label for the points earned', 'yayloyalty' ),
            'balance'            => __( 'Balance after this award', 'yayloyalty' ),
            'balance_points'     => __( 'Balance with its label, e.g. "150 Points"', 'yayloyalty' ),
            'balance_value'      => __( 'What the balance is worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
            'balance_value_text' => __( 'Sentence saying what the balance is worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
            'order_number'       => __( 'Order number the points were earned for; empty unless earned from an order', 'yayloyalty' ),
            'points_url'         => __( 'Link to the customer\'s points; empty unless a site sets yayloyalty_points_earned_cta_url', 'yayloyalty' ),
        ];
    }
}
