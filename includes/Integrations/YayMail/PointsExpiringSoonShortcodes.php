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
        'customer_name'   => 'greeting_name',
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
            'customer_name'     => __( 'Customer first name, else display name', 'yayloyalty' ),
            'expiring_points'   => __( 'Sentence saying how many points expire, and when', 'yayloyalty' ),
            'points'            => __( 'Points that expire', 'yayloyalty' ),
            'points_label'      => __( 'Points label for the points that expire', 'yayloyalty' ),
            'expiry_date'       => __( 'The date they expire; the first one when several batches expire', 'yayloyalty' ),
            'points_value'      => __( 'What the expiring points are worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
            'points_value_text' => __( 'Sentence saying what the expiring points are worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
            'points_url'        => __( 'Link to the customer\'s points; empty unless a site sets yayloyalty_points_expiring_soon_cta_url', 'yayloyalty' ),
        ];
    }
}
