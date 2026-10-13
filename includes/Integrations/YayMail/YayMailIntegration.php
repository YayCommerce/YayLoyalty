<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Lets merchants design the Points earned and Points expired emails in a licensed YayMail.
 * The adapter classes extend YayMail's own, so they are first named inside
 * YayMail's register hooks: autoloading them earlier, or without YayMail, is a fatal.
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
        $yaymail_emails->register( PointsExpiredYayMailEmail::get_instance() );
    }

    public function register_shortcodes(): void {
        PointsEarnedShortcodes::get_instance();
        PointsExpiredShortcodes::get_instance();
    }
}
