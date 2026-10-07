<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayLoyalty\Helpers\EmailsHelper;
use YayMail\Abstracts\BaseEmail;
use YayMail\Elements\ElementsLoader;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points earned email as YayMail lists it: while its YayMail template is
 * enabled, BaseEmail swaps WooCommerce's template for the YayMail design.
 *
 * @method static PointsEarnedYayMailEmail get_instance()
 */
class PointsEarnedYayMailEmail extends BaseEmail {

    use SingletonTrait;

    public $email_types = [ YAYMAIL_NON_ORDER_EMAILS ];

    protected $source = [
        'plugin_id'   => 'yayloyalty',
        'plugin_name' => 'YayLoyalty',
    ];

    protected function __construct() {
        $email = EmailsHelper::registered_emails()[ PointsEarnedEmail::ID ] ?? null;

        if ( ! $email instanceof PointsEarnedEmail ) {
            return;
        }

        $this->id         = $email->id;
        $this->title      = $email->get_title();
        $this->root_email = $email;
        $this->recipient  = function_exists( 'yaymail_get_email_recipient_zone' ) ? yaymail_get_email_recipient_zone( $email ) : '';

        $this->render_priority = apply_filters( 'yaymail_email_render_priority', $this->render_priority, $this->id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- YayMail's own hook, applied as its core emails do.
        add_filter( 'wc_get_template', [ $this, 'get_template_file' ], $this->render_priority ?? 10, 3 );
        $this->maybe_disable_block_email_editor();
    }

    /**
     * The merchant-editable starting layout. Its wording copies the `yayloyalty-points-earned.php` templates
     * and PointsEarnedEmail::get_template_values(), which own it, with the same msgids: change them together.
     * Unlike the templates, it prints the greeting even when the customer's name is ''. It has no
     * "View your points" button: the link is '' unless a site filters it in, and a static button cannot hide itself.
     */
    public function get_default_elements() {
        /* translators: %s: customer's first name. */
        $greeting = sprintf( esc_html__( 'Hi %s,', 'yayloyalty' ), PointsEarnedShortcodes::tag( 'customer_name' ) );
        /* translators: %s: points with label, e.g. "120 Points". */
        $balance = sprintf( esc_html__( 'Your balance is now %s.', 'yayloyalty' ), '<strong>' . PointsEarnedShortcodes::tag( 'balance_points' ) . '</strong>' );
        $text    = '<p>' . $greeting . '</p><p>' . PointsEarnedShortcodes::tag( 'earned_points' ) . '</p><p>' . $balance . '</p><p>' . PointsEarnedShortcodes::tag( 'balance_value_text' ) . '</p>';

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
                        'rich_text' => $text,
                    ],
                ],
                [
                    'type' => 'Footer',
                ],
            ]
        );
    }

    /**
     * Rewrites WooCommerce placeholders such as {points_label} as this email's YayMail shortcodes:
     * YayMail renders shortcodes in its elements but never runs WC_Email::format_string() on them.
     */
    private static function placeholders_to_shortcodes( string $text ): string {
        return strtr(
            $text,
            [
                '{points}'       => PointsEarnedShortcodes::tag( 'points' ),
                '{points_label}' => PointsEarnedShortcodes::tag( 'points_label' ),
                '{balance}'      => PointsEarnedShortcodes::tag( 'balance' ),
                '{site_title}'   => '[yaymail_site_name]',
            ]
        );
    }

    public function get_template_path() {
        return __DIR__ . '/templates/yaymail-points-earned.php';
    }
}
