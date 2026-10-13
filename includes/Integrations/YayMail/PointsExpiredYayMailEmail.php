<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Engine\Emails\PointsExpiredEmail;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points expired email in YayMail.
 *
 * @method static PointsExpiredYayMailEmail get_instance()
 */
class PointsExpiredYayMailEmail extends LedgerEntryYayMailEmail {

    use SingletonTrait;

    protected function email_id(): string {
        return PointsExpiredEmail::ID;
    }

    /** The `yayloyalty-points-expired.php` templates' sentences, from LoyaltyEmail and PointsExpiredEmail's template values. */
    protected function default_body(): string {
        $greeting = LoyaltyEmail::greeting_text( LedgerEntryShortcodes::tag( 'customer_name' ) );
        $balance  = LoyaltyEmail::balance_sentence( LedgerEntryShortcodes::tag( 'balance_points' ) );

        return '<p>' . $greeting . '</p><p>' . LedgerEntryShortcodes::tag( 'expired_points' ) . '</p><p>' . $balance . '</p>';
    }
}
