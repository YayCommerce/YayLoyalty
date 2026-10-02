<?php
namespace YayLoyalty\Engine\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Base for loyalty emails: a subclass sets its id, title, description and group;
 * its template names follow the id (yayloyalty_points_earned →
 * emails/yayloyalty-points-earned.php). get_content_html/plain pass
 * `template_base` as WooCommerce's default template path, so a theme copy under
 * woocommerce/emails/ still wins and no template-locating filters are needed.
 */
abstract class LoyaltyEmail extends \WC_Email {

    /** Whether a new store sends this email; after that the merchant's choice in WooCommerce decides. */
    protected $is_enabled_by_default = true;

    public function __construct() {
        $template_file        = str_replace( '_', '-', $this->id ) . '.php';
        $this->template_html  = 'emails/' . $template_file;
        $this->template_plain = 'emails/plain/' . $template_file;
        $this->customer_email = true;
        $this->template_base  = $this->templates_dir();

        parent::__construct();
    }

    /** Folder holding this email's templates; a subclass whose templates live elsewhere overrides it. */
    protected function templates_dir(): string {
        return __DIR__ . '/templates/';
    }

    public function init_form_fields() {
        parent::init_form_fields();

        $this->form_fields['enabled']['default'] = $this->is_enabled_by_default ? 'yes' : 'no';
    }

    public function get_content_html() {
        return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
    }

    public function get_content_plain() {
        return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
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
     * Variables only this email's templates receive, on top of the shared ones.
     * An override returns [] while $this->object is not what it sends for
     * (WooCommerce's preview sets a dummy order); its templates then fall back.
     *
     * @return array<string, mixed>
     */
    protected function extra_template_args(): array {
        return [];
    }

    /** Fills this email with sample data for WooCommerce's settings preview; no-op until the email has data of its own. */
    public function prepare_preview(): void {}
}
