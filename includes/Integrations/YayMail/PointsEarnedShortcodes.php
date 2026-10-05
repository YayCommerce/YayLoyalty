<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayMail\Abstracts\BaseShortcode;
use YayMail\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * The Points earned email's YayMail shortcodes. Every value comes from
 * PointsEarnedEmail::get_template_values(), so the YayMail design and the
 * WooCommerce template never word or format an award differently.
 *
 * @method static PointsEarnedShortcodes get_instance()
 */
class PointsEarnedShortcodes extends BaseShortcode {

    use SingletonTrait;

    /** Shortcodes whose template value has another name. */
    private const VALUE_KEYS = [
        'customer_name'  => 'greeting_name',
        'earned_points'  => 'earned_text',
        'balance_points' => 'balance_text',
        'points_url'     => 'cta_url',
    ];

    /** Values that are already escaped HTML. */
    private const HTML_KEYS = [ 'earned_points', 'balance_value', 'balance_value_text' ];

    public $available_email_ids = [ PointsEarnedEmail::ID ];

    /** @var object|null The award $values were built for; one build serves every shortcode in a render. */
    private $values_entry = null;

    /** @var array<string, mixed> */
    private $values = [];

    /** @var array<string, mixed>|null */
    private $sample_values = null;

    /** @var PointsEarnedEmail|null The email whose subject is being filtered; YayMail's subject render does not pass it. */
    private $subject_email = null;

    protected function __construct() {
        parent::__construct();

        // Around YayMail's own subject filter, at the default 10.
        add_filter( 'woocommerce_email_subject_' . PointsEarnedEmail::ID, [ $this, 'hold_subject_email' ], 9, 3 );
        add_filter( 'woocommerce_email_subject_' . PointsEarnedEmail::ID, [ $this, 'release_subject_email' ], 11 );
    }

    /**
     * @param string $subject
     * @param mixed  $award The email's object, unused.
     * @param mixed  $email
     * @return string
     */
    public function hold_subject_email( $subject, $award = null, $email = null ) {
        $this->subject_email = $email instanceof PointsEarnedEmail ? $email : null;

        return $subject;
    }

    /**
     * @param string $subject
     * @return string
     */
    public function release_subject_email( $subject ) {
        $this->subject_email = null;

        return $subject;
    }

    /** A shortcode's name: 'customer_name' → 'yaymail_yayloyalty_customer_name'. */
    private static function name( string $key ): string {
        return 'yaymail_yayloyalty_' . $key;
    }

    /** A shortcode as written in a design: 'customer_name' → '[yaymail_yayloyalty_customer_name]'. */
    public static function tag( string $key ): string {
        return '[' . self::name( $key ) . ']';
    }

    public function get_shortcodes() {
        $shortcodes = [];

        foreach ( self::descriptions() as $key => $description ) {
            $shortcodes[] = [
                'name'          => self::name( $key ),
                'description'   => $description,
                'group'         => PointsEarnedEmail::ID,
                'callback'      => [ $this, 'render' ],
                'callback_args' => [ 'yayloyalty_key' => $key ],
            ];
        }

        return $shortcodes;
    }

    /** @return array<string, string> Shortcode key (after the prefix) => editor description. */
    private static function descriptions(): array {
        return [
            'customer_name'      => __( 'Customer first name, else display name', 'yayloyalty' ),
            'earned_points'      => __( 'Sentence saying what was earned and why', 'yayloyalty' ),
            'points'             => __( 'Points earned', 'yayloyalty' ),
            'points_label'       => __( 'Points label for the points earned', 'yayloyalty' ),
            'balance'            => __( 'Balance after this award', 'yayloyalty' ),
            'balance_points'     => __( 'Balance with its label, e.g. "150 Points"', 'yayloyalty' ),
            'balance_value'      => __( 'What the balance is worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
            'balance_value_text' => __( 'Sentence saying what the balance is worth; empty until redemption ships, or while the discount reward is not live', 'yayloyalty' ),
            'order_number'       => __( 'Order number the points were earned for; empty unless earned from an order', 'yayloyalty' ),
            'points_url'         => __( 'Link to the customer\'s points; empty unless a site sets yayloyalty_points_earned_cta_url', 'yayloyalty' ),
        ];
    }

    /**
     * One shortcode's output, picked by the `yayloyalty_key` its callback_args added.
     *
     * @param array<string, mixed> $data YayMail's render data plus callback_args.
     */
    public function render( array $data ): string {
        $key       = (string) ( $data['yayloyalty_key'] ?? '' );
        $value_key = self::VALUE_KEYS[ $key ] ?? $key;
        $values    = $this->values( $data['render_data'] ?? [] );

        if ( ! isset( $values[ $value_key ] ) || ! is_string( $values[ $value_key ] ) ) {
            return '';
        }

        if ( 'points_url' === $key ) {
            return esc_url( $values[ $value_key ] );
        }

        return in_array( $key, self::HTML_KEYS, true ) ? $values[ $value_key ] : esc_html( $values[ $value_key ] );
    }

    /**
     * The values of the award being sent, or of the sample award for YayMail's
     * editor, preview and test send, which render with `is_sample` or an order.
     *
     * @param mixed $render_data The body passes the WooCommerce template args, `email` included.
     */
    private function values( $render_data ): array {
        $render_data = is_array( $render_data ) ? $render_data : [];
        $root_email  = PointsEarnedYayMailEmail::get_instance()->get_root_email();

        if ( ! empty( $render_data['is_sample'] ) || ! empty( $render_data['order'] ) ) {
            return $this->sample_values( $root_email );
        }

        // YayMail's subject filter renders a real send with empty render_data; hold_subject_email() caught the email being sent.
        $email = $render_data['email'] ?? $this->subject_email;

        if ( ! $email instanceof PointsEarnedEmail ) {
            return [];
        }

        if ( $email->object !== $this->values_entry ) {
            $this->values_entry = $email->object;
            $this->values       = $email->get_template_values();
        }

        return $this->values;
    }

    /** @param mixed $root_email */
    private function sample_values( $root_email ): array {
        if ( null === $this->sample_values && $root_email instanceof PointsEarnedEmail ) {
            // A clone: the shared mailer instance may be mid-send.
            $sample = clone $root_email;
            $sample->prepare_preview();
            $this->sample_values = $sample->get_template_values();
        }

        return $this->sample_values ?? [];
    }
}
