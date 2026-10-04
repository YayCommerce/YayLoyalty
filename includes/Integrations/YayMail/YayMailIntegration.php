<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Lets merchants design the Points earned email in a licensed YayMail.
 * The adapter classes extend YayMail's own, so they are first named inside
 * YayMail's register hooks: autoloading them earlier, or without YayMail, is a fatal.
 */
class YayMailIntegration {

    use SingletonTrait;

    protected function __construct() {
        if ( ! self::is_yaymail_active() ) {
            return;
        }

        add_action( 'yaymail_register_emails', [ $this, 'register_emails' ] );
        add_action( 'yaymail_register_shortcodes', [ $this, 'register_shortcodes' ] );
    }

    /** Whether YayMail is loaded and, for a build that checks licences, licensed. */
    private static function is_yaymail_active(): bool {
        if ( ! class_exists( 'YaymailPluginAdapter', false ) ) {
            return false;
        }

        return ! method_exists( 'YaymailPluginAdapter', 'is_licensed' ) || \YaymailPluginAdapter::is_licensed();
    }

    /** @param \YayMail\Emails\YayMailEmails $yaymail_emails */
    public function register_emails( $yaymail_emails ): void {
        $yaymail_emails->register( PointsEarnedYayMailEmail::get_instance() );
    }

    public function register_shortcodes(): void {
        PointsEarnedShortcodes::get_instance();
    }
}
