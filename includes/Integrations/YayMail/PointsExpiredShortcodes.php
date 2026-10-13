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
class PointsExpiredShortcodes extends LedgerEntryShortcodes {

    use SingletonTrait;

    protected const VALUE_KEYS = [
        'customer_name'  => 'greeting_name',
        'expired_points' => 'expired_text',
        'balance_points' => 'balance_text',
    ];

    protected const HTML_KEYS = [ 'expired_points', 'balance_value', 'balance_value_text' ];

    public $available_email_ids = [ PointsExpiredEmail::ID ];

    protected function yaymail_email(): LedgerEntryYayMailEmail {
        return PointsExpiredYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'customer_name'      => __( 'Customer first name, else display name', 'yayloyalty' ),
            'expired_points'     => __( 'Sentence saying how many points expired', 'yayloyalty' ),
            'points'             => __( 'Points expired', 'yayloyalty' ),
            'points_label'       => __( 'Points label for the points expired', 'yayloyalty' ),
            'balance'            => __( 'Balance after the expiry', 'yayloyalty' ),
            'balance_points'     => __( 'Balance with its label, e.g. "150 Points"', 'yayloyalty' ),
            'balance_value'      => __( 'What the balance is worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
            'balance_value_text' => __( 'Sentence saying what the balance is worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
        ];
    }
}
