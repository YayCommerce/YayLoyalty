<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Lets merchants design the Points earned, Points expired and Points expiring soon emails in a licensed YayMail.
 * The expiry emails only while expiry is on, as WooCommerce only knows them then.
 * The adapter classes extend YayMail's own, so they are first named inside
 * YayMail's register hooks: autoloading them earlier, or without YayMail, is a fatal. Other editions' emails
 * register through the yayloyalty_yaymail_register_emails and yayloyalty_yaymail_register_shortcodes actions.
 */
class YayMailIntegration {

    use SingletonTrait;

    /** The first YayMail that passes the email to its subject's shortcodes. */
    private const MIN_YAYMAIL_VERSION = '4.4.7';

    protected function __construct() {
        if ( ! self::is_yaymail_active() ) {
            return;
        }

        add_action( 'yaymail_register_emails', [ $this, 'register_emails' ] );
        add_action( 'yaymail_register_shortcodes', [ $this, 'register_shortcodes' ] );
    }

    /** Whether YayMail is loaded, at least MIN_YAYMAIL_VERSION and, for a build that checks licences, licensed. */
    private static function is_yaymail_active(): bool {
        if ( ! defined( 'YAYMAIL_VERSION' ) || version_compare( YAYMAIL_VERSION, self::MIN_YAYMAIL_VERSION, '<' ) ) {
            return false;
        }

        if ( ! class_exists( 'YaymailPluginAdapter', false ) ) {
            return false;
        }

        return ! method_exists( 'YaymailPluginAdapter', 'is_licensed' ) || \YaymailPluginAdapter::is_licensed();
    }

    /** @param \YayMail\Emails\YayMailEmails $yaymail_emails */
    public function register_emails( $yaymail_emails ): void {
        $yaymail_emails->register( PointsEarnedYayMailEmail::get_instance() );

        if ( Emails::is_expiry_on() ) {
            $yaymail_emails->register( PointsExpiredYayMailEmail::get_instance() );
            $yaymail_emails->register( PointsExpiringSoonYayMailEmail::get_instance() );
        }

        /**
         * Fires inside YayMail's yaymail_register_emails, only while YayMail is active, at least 4.4.7 and licensed,
         * so a subscriber may name YayMail's classes here to register its own loyalty emails.
         *
         * @param \YayMail\Emails\YayMailEmails $yaymail_emails YayMail's email registry.
         */
        do_action( 'yayloyalty_yaymail_register_emails', $yaymail_emails );
    }

    public function register_shortcodes(): void {
        PointsEarnedShortcodes::get_instance();

        if ( Emails::is_expiry_on() ) {
            PointsExpiredShortcodes::get_instance();
            PointsExpiringSoonShortcodes::get_instance();
        }

        /**
         * Fires inside YayMail's yaymail_register_shortcodes, only while YayMail is active, at least 4.4.7 and
         * licensed, so a subscriber may name YayMail's classes here to register its loyalty emails' shortcodes.
         */
        do_action( 'yayloyalty_yaymail_register_shortcodes' );
    }
}
