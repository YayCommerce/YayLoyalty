<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Base for loyalty emails. Template names follow the id (yayloyalty_points_earned →
 * emails/yayloyalty-points-earned.php). `template_base` goes in as WooCommerce's default template path,
 * so a theme copy under woocommerce/emails/ still wins without template-locating filters.
 */
abstract class LoyaltyEmail extends \WC_Email {

    /** Send result: the transport accepted the email. */
    public const SEND_SENT = 'sent';

    /** Send result: nothing was sent on purpose (email off, no customer or address, weekly cap, row refused). */
    public const SEND_SKIPPED = 'skipped';

    /** Send result: the transport refused the email. */
    public const SEND_FAILED = 'failed';

    /** Whether a new store sends this email. After that, the merchant's choice in WooCommerce decides. */
    protected $is_enabled_by_default = true;

    /** Whether the weekly cap (EmailThrottle) can stop this email. An uncapped send still counts toward the week. */
    protected $is_capped = true;

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
     * Fills {points_label} with the merchant's plural label unless the email set a count-based one, as
     * PointsEarnedEmail does. Read on every call, so it follows the customer's locale while sending.
     *
     * @param mixed $text Subject, heading or additional content.
     * @return string
     */
    public function format_string( $text ) {
        if ( ! empty( $this->placeholders['{points_label}'] ) ) {
            return parent::format_string( $text );
        }

        $this->placeholders['{points_label}'] = SettingsHelper::points_label_plural();
        $formatted                            = parent::format_string( $text );
        $this->placeholders['{points_label}'] = '';

        return $formatted;
    }

    /**
     * Subjects are plain text, so this decodes the entities that a number's separators (&nbsp; as a
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
     * The send every trigger shares: checks the email is on for $subject (what is_enabled()'s filter
     * receives), switches to the customer's locale, runs $prepare to fill the placeholders and values,
     * then mails the customer through send_to_customer().
     *
     * @param mixed    $subject What this email is about, set as $this->object.
     * @param callable $prepare Fills placeholders and template values; runs in the customer's locale.
     * @return string One of the SEND_* results. A throwing transport propagates.
     */
    protected function send_prepared( int $user_id, $subject, callable $prepare ): string {
        $this->object = $subject;

        if ( ! $this->is_enabled() ) {
            return self::SEND_SKIPPED;
        }

        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return self::SEND_SKIPPED;
        }

        // Before the placeholders: the default point labels are translated strings.
        $this->setup_locale();

        try {
            $prepare();
            $this->recipient = $user->user_email;

            return $this->get_recipient() ? $this->send_to_customer( $user_id ) : self::SEND_SKIPPED;
        } finally {
            // A throwing mail transport must not leave the rest of the request in the customer's locale.
            $this->restore_locale();
        }
    }

    /**
     * Sends this email to its customer, within the weekly cap (EmailThrottle) unless the email is uncapped.
     * Only a send the transport accepts counts against the cap.
     *
     * @return string SEND_SKIPPED when the cap stops it, else whether the transport accepted it. A throwing transport propagates.
     */
    protected function send_to_customer( int $user_id ): string {
        if ( $this->is_capped && ! EmailThrottle::allows( $user_id ) ) {
            return self::SEND_SKIPPED;
        }

        if ( ! $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() ) ) {
            return self::SEND_FAILED;
        }

        EmailThrottle::record( $user_id );

        return self::SEND_SENT;
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

    /** Every value the email shows for what it is sending now, for other renderers such as YayMail. [] until one is prepared. */
    public function get_template_values(): array {
        return $this->extra_template_args();
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

    /** First name, else display name, else '' (the template then drops the greeting). */
    protected static function greeting_name( int $user_id ): string {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return '';
        }

        return $user->first_name ? $user->first_name : $user->display_name;
    }

    /**
     * What the balance buys at the live discount rate, as wc_price() HTML.
     * '' while the reward is not live, or when there is nothing to spend.
     */
    protected static function balance_value( int $balance ): string {
        $amount = PointsMath::points_to_currency( $balance, ( new RewardRepository() )->live_points_per_currency_unit(), wc_get_price_decimals() );

        if ( null === $amount || $amount <= 0 ) {
            return '';
        }

        // The rate is in the store's base currency: a currency switcher's session currency would mislabel it.
        return wc_price( $amount, [ 'currency' => get_option( 'woocommerce_currency' ) ] );
    }

    /** "Hi Anna," around escaped HTML, or '' when that is ''. Public for YayMail, which passes a shortcode tag. */
    public static function greeting_text( string $name_html ): string {
        /* translators: %s: customer's first name. */
        return '' === $name_html ? '' : sprintf( esc_html__( 'Hi %s,', 'yayloyalty' ), $name_html );
    }

    /** "Your balance is now <strong>120 Points</strong>." around escaped HTML. Public for YayMail, which passes a shortcode tag. */
    public static function balance_sentence( string $balance_html ): string {
        /* translators: %s: points with label, e.g. "120 Points". */
        return sprintf( esc_html__( 'Your balance is now %s.', 'yayloyalty' ), '<strong>' . $balance_html . '</strong>' );
    }

    /** "That's worth <strong>$1.50</strong> in rewards." around balance_value(), or '' when that is ''. */
    protected static function balance_value_text( string $balance_value ): string {
        /* translators: %s: money amount, e.g. "$1.50". */
        return '' === $balance_value ? '' : sprintf( esc_html__( 'That\'s worth %s in rewards.', 'yayloyalty' ), '<strong>' . $balance_value . '</strong>' );
    }

    /** Fills this email with sample data for WooCommerce's settings preview. A no-op until the email has data of its own. */
    public function prepare_preview(): void {}
}
