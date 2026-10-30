<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Engine\Emails\PointsAdjustedEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points adjusted email in YayMail.
 *
 * @method static PointsAdjustedYayMailEmail get_instance()
 */
class PointsAdjustedYayMailEmail extends LoyaltyYayMailEmail {

    use SingletonTrait;

    protected function email_id(): string {
        return PointsAdjustedEmail::ID;
    }

    /**
     * The `yayloyalty-points-adjusted.php` templates' sentences, from LoyaltyEmail and PointsAdjustedEmail's
     * template values. Unlike the templates, it keeps the note's paragraph when the adjustment has no note:
     * a static design cannot hide it.
     */
    protected function default_body(): string {
        $greeting = LoyaltyEmail::greeting_text( LoyaltyEmailShortcodes::tag( 'customer_name' ) );
        $balance  = LoyaltyEmail::balance_sentence( LoyaltyEmailShortcodes::tag( 'balance_points' ) );

        return '<p>' . $greeting . '</p><p>' . LoyaltyEmailShortcodes::tag( 'adjusted_message' ) . '</p><p>' . LoyaltyEmailShortcodes::tag( 'customer_note' ) . '</p><p>' . $balance . '</p><p>' . LoyaltyEmailShortcodes::tag( 'balance_value_text' ) . '</p>';
    }
}
