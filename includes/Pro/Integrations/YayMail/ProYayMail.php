<?php
namespace YayLoyalty\Pro\Integrations\YayMail;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Pro loyalty emails with a licensed YayMail, through Lite's yayloyalty_yaymail_register_* actions.
 * The adapters extend YayMail's classes, so they are first named here, inside YayMail's hooks: autoloading them
 * earlier, or without YayMail, is a fatal.
 */
final class ProYayMail {

    /** @param \YayMail\Emails\YayMailEmails $yaymail_emails */
    public static function register_emails( $yaymail_emails ): void {
        $yaymail_emails->register( RewardClaimedYayMailEmail::get_instance() );
        $yaymail_emails->register( BirthdayPointsYayMailEmail::get_instance() );
    }

    public static function register_shortcodes(): void {
        RewardClaimedShortcodes::get_instance();
        BirthdayPointsShortcodes::get_instance();
    }
}
