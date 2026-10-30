<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points expiring soon email in YayMail.
 *
 * @method static PointsExpiringSoonYayMailEmail get_instance()
 */
class PointsExpiringSoonYayMailEmail extends LoyaltyYayMailEmail {

    use SingletonTrait;

    protected function email_id(): string {
        return PointsExpiringSoonEmail::ID;
    }

    /**
     * The `yayloyalty-points-expiring-soon.php` templates' sentences, from PointsExpiringSoonEmail's template
     * values. It leaves out the inactivity-only "Earn or redeem" sentence and the "Use your points" button:
     * a static design cannot hide them when they do not apply.
     */
    protected function default_body(): string {
        $greeting = LoyaltyEmail::greeting_text( LoyaltyEmailShortcodes::tag( 'customer_name' ) );

        return '<p>' . $greeting . '</p><p>' . LoyaltyEmailShortcodes::tag( 'expiring_message' ) . '</p><p>' . LoyaltyEmailShortcodes::tag( 'points_value_text' ) . '</p>';
    }
}
