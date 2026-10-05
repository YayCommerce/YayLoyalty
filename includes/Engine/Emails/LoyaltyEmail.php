<?php
namespace YayLoyalty\Engine\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Base for loyalty emails. Template names follow the id (yayloyalty_points_earned →
 * emails/yayloyalty-points-earned.php). `template_base` goes in as WooCommerce's default template path,
 * so a theme copy under woocommerce/emails/ still wins without template-locating filters.
 */
abstract class LoyaltyEmail extends \WC_Email {

    /** Whether a new store sends this email. After that, the merchant's choice in WooCommerce decides. */
    protected $is_enabled_by_default = true;

    public function __construct() {
        $template_file        = str_replace( '_', '-', $this->id ) . '.php';
        $this->template_html  = 'emails/' . $template_file;
        $this->template_plain = 'emails/plain/' . $template_file;
        $this->customer_email = true;
        $this->template_base  = $this->templates_dir();

        parent::__construct();
    }

    /** Folder holding this email's templates. A subclass whose templates live elsewhere overrides it. */
    protected function templates_dir(): string {
        return __DIR__ . '/templates/';
    }

    public function init_form_fields() {
        parent::init_form_fields();

        $this->form_fields['enabled']['default'] = $this->is_enabled_by_default ? 'yes' : 'no';
    }

    /**
     * Subjects are plain text, so this decodes the entities that number_format_i18n() (&nbsp; as a
     * thousands separator) and escaped placeholders leave, which WC_Email::send() keeps. It runs after
     * the subject filter, so it covers what other plugins add there too.
     */
    public function get_subject() {
        return html_entity_decode( parent::get_subject(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    public function get_content_html() {
        return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
    }

    /**
     * Decodes the entities that esc_html() and wptexturize() leave in plain templates, such as &#039;
     * and &#8230;, because WooCommerce's plain-text cleanup deletes every entity it does not list.
     * <, > and & go back as &lt;, &gt; and &amp;: WooCommerce decodes those only after it strips tags,
     * so text such as "<3" survives.
     */
    public function get_content_plain() {
        return preg_replace_callback(
            '/&[^&\s;]+;/',
            static function ( array $entity ): string {
                $char    = html_entity_decode( $entity[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $encoded = [
                    '<' => '&lt;',
                    '>' => '&gt;',
                    '&' => '&amp;',
                ];

                return $encoded[ $char ] ?? $char;
            },
            wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base )
        );
    }

    /**
     * Sends this email to its customer, within the weekly cap (EmailThrottle).
     * Only a send the transport accepts counts against the cap.
     */
    protected function send_to_customer( int $user_id ): bool {
        if ( ! EmailThrottle::allows( $user_id ) ) {
            return false;
        }

        $sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

        if ( $sent ) {
            EmailThrottle::record( $user_id );
        }

        return $sent;
    }

    /** The variables every loyalty template receives, named as WooCommerce's own templates name them. */
    private function template_args( bool $plain_text ): array {
        return array_merge(
            [
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => false,
                'plain_text'         => $plain_text,
                'email'              => $this,
            ],
            $this->extra_template_args()
        );
    }

    /**
     * Variables only this email's templates receive. An override returns [] while $this->object is not
     * what it sends for, such as the preview's dummy order, and its templates then fall back.
     *
     * @return array<string, mixed>
     */
    protected function extra_template_args(): array {
        return [];
    }

    /** Fills this email with sample data for WooCommerce's settings preview. A no-op until the email has data of its own. */
    public function prepare_preview(): void {}
}
