<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\PointsAdjustedEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points adjusted email's YayMail shortcodes, from PointsAdjustedEmail's template values.
 *
 * @method static PointsAdjustedShortcodes get_instance()
 */
class PointsAdjustedShortcodes extends LoyaltyEmailShortcodes {

    use SingletonTrait;

    protected const VALUE_KEYS = [
        'adjusted_message' => 'adjusted_text',
        'balance_points'   => 'balance_text',
    ];

    protected const HTML_KEYS = [ 'adjusted_message' ];

    public $available_email_ids = [ PointsAdjustedEmail::ID ];

    protected function yaymail_email(): LoyaltyYayMailEmail {
        return PointsAdjustedYayMailEmail::get_instance();
    }

    protected function descriptions(): array {
        return [
            'adjusted_message' => __( 'Points Adjusted Message', 'yayloyalty' ),
            'points'           => __( 'Points Adjusted', 'yayloyalty' ),
            'customer_note'    => __( 'Note For Customer', 'yayloyalty' ),
        ] + self::shared_descriptions( 'points_label', 'balance', 'balance_points', 'balance_value', 'balance_value_text' );
    }
}
