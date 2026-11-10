<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Helpers\EmailsHelper;
use YayMail\Abstracts\BaseEmail;
use YayMail\Elements\ElementsLoader;

defined( 'ABSPATH' ) || exit;

/**
 * A loyalty email as YayMail lists it: while its YayMail template is enabled, BaseEmail swaps
 * WooCommerce's template for the YayMail design. Each subclass names its email and its starting body,
 * and uses YayMail's SingletonTrait itself, so each keeps its own instance.
 */
abstract class LoyaltyYayMailEmail extends BaseEmail {

    public $email_types = [ YAYMAIL_NON_ORDER_EMAILS ];

    protected $source = [
        'plugin_id'   => 'yayloyalty',
        'plugin_name' => 'YayLoyalty',
    ];

    /** @var array<string, LoyaltyYayMailEmail> Each constructed instance by its email id, for the shared template. */
    private static $by_email_id = [];

    /** The WooCommerce email's id, e.g. PointsEarnedEmail::ID. */
    abstract protected function email_id(): string;

    /** The starting body's HTML, written with LoyaltyEmailShortcodes::tag(). */
    abstract protected function default_body(): string;

    protected function __construct() {
        $email = EmailsHelper::registered_emails()[ $this->email_id() ] ?? null;

        if ( ! $email instanceof LoyaltyEmail ) {
            return;
        }

        $this->id         = $email->id;
        $this->title      = $email->get_title();
        $this->root_email = $email;
        $this->recipient  = function_exists( 'yaymail_get_email_recipient_zone' ) ? yaymail_get_email_recipient_zone( $email ) : '';

        self::$by_email_id[ $email->id ] = $this;

        $this->render_priority = apply_filters( 'yaymail_email_render_priority', $this->render_priority, $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- YayMail's own hook, applied as its core emails do.
        add_filter( 'wc_get_template', [ $this, 'get_template_file' ], $this->render_priority ?? 10, 3 );
        $this->maybe_disable_block_email_editor();
    }

    /** The YayMail email for a WooCommerce email id, once constructed. */
    public static function for_email_id( string $email_id ): ?self {
        return self::$by_email_id[ $email_id ] ?? null;
    }

    /** The merchant-editable starting layout: logo, the email's heading, its body, its button and the footer. */
    public function get_default_elements() {
        return ElementsLoader::load_elements(
            [
                [
                    'type' => 'Logo',
                ],
                [
                    'type'       => 'Heading',
                    'attributes' => [
                        'rich_text' => self::placeholders_to_shortcodes( esc_html( $this->root_email->get_default_heading() ) ),
                    ],
                ],
                [
                    'type'       => 'Text',
                    'attributes' => [
                        'rich_text' => $this->default_body(),
                    ],
                ],
                [
                    'type'       => 'Button',
                    'attributes' => [
                        'text' => LoyaltyEmailShortcodes::tag( 'cta_text' ),
                        'url'  => LoyaltyEmailShortcodes::tag( 'cta_url' ),
                    ],
                ],
                [
                    'type' => 'Footer',
                ],
            ]
        );
    }

    /**
     * Rewrites WooCommerce placeholders such as {points_label} as YayMail shortcodes:
     * YayMail renders shortcodes in its elements but never runs WC_Email::format_string() on them.
     */
    private static function placeholders_to_shortcodes( string $text ): string {
        return strtr(
            $text,
            [
                '{points}'       => LoyaltyEmailShortcodes::tag( 'points' ),
                '{points_label}' => LoyaltyEmailShortcodes::tag( 'points_label' ),
                '{balance}'      => LoyaltyEmailShortcodes::tag( 'balance' ),
                '{expiry_date}'  => LoyaltyEmailShortcodes::tag( 'expiry_date' ),
                '{order_number}' => LoyaltyEmailShortcodes::tag( 'order_number' ),
                '{site_title}'   => '[yaymail_site_name]',
            ]
        );
    }

    public function get_template_path() {
        return __DIR__ . '/templates/yaymail-loyalty-email.php';
    }
}
