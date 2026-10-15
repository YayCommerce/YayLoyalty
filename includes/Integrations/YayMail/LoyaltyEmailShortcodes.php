<?php
namespace YayLoyalty\Integrations\YayMail;

use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayMail\Abstracts\BaseShortcode;

defined( 'ABSPATH' ) || exit;

/**
 * A loyalty email's YayMail shortcodes. Every value comes from the email's own template values
 * (LoyaltyEmail::get_template_values()), so the YayMail design and the WooCommerce template never
 * word or format a value differently. YayMail keeps shortcodes per email, so emails share the names.
 * Each subclass lists its shortcodes and uses YayMail's SingletonTrait itself.
 */
abstract class LoyaltyEmailShortcodes extends BaseShortcode {

    /** Shortcodes whose template value has another name, shared by every loyalty email. A subclass adds its own. */
    protected const VALUE_KEYS = [
        'customer_name' => 'greeting_name',
    ];

    /** Values that are already escaped HTML. */
    protected const HTML_KEYS = [ 'balance_value', 'balance_value_text' ];

    /** @var array<string, mixed>|null */
    private $sample_values = null;

    /** The YayMail email these shortcodes belong to. */
    abstract protected function yaymail_email(): LoyaltyYayMailEmail;

    /** @return array<string, string> Shortcode key (after the prefix) => editor description, besides customer_name. */
    abstract protected function descriptions(): array;

    /** @return array<string, string> Every shortcode's editor description: the shared customer_name, then the email's own. */
    private function all_descriptions(): array {
        return [ 'customer_name' => __( 'Customer first name, else display name', 'yayloyalty' ) ] + $this->descriptions();
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

        foreach ( $this->all_descriptions() as $key => $description ) {
            $shortcodes[] = [
                'name'          => self::name( $key ),
                'description'   => $description,
                'group'         => $this->available_email_ids[0],
                'callback'      => [ $this, 'render' ],
                'callback_args' => [ 'yayloyalty_key' => $key ],
            ];
        }

        return $shortcodes;
    }

    /**
     * One shortcode's output, picked by the `yayloyalty_key` its callback_args added.
     *
     * @param array<string, mixed> $data YayMail's render data plus callback_args.
     */
    public function render( array $data ): string {
        $key       = (string) ( $data['yayloyalty_key'] ?? '' );
        $value_key = ( static::VALUE_KEYS + self::VALUE_KEYS )[ $key ] ?? $key;
        $values    = $this->values( $data['render_data'] ?? [] );

        if ( ! isset( $values[ $value_key ] ) || ! is_string( $values[ $value_key ] ) ) {
            return '';
        }

        if ( 'points_url' === $key ) {
            return esc_url( $values[ $value_key ] );
        }

        return in_array( $key, static::HTML_KEYS, true ) ? $values[ $value_key ] : esc_html( $values[ $value_key ] );
    }

    /**
     * The values of what is being sent, or of the sample for YayMail's editor, preview and
     * test send, which render with `is_sample` or an order.
     *
     * @param mixed $render_data The body passes the WooCommerce template args and the subject passes the email; both include `email`.
     */
    private function values( $render_data ): array {
        $render_data = is_array( $render_data ) ? $render_data : [];
        $root_email  = $this->yaymail_email()->get_root_email();

        if ( ! $root_email instanceof LoyaltyEmail ) {
            return [];
        }

        if ( ! empty( $render_data['is_sample'] ) || ! empty( $render_data['order'] ) ) {
            return $this->sample_values( $root_email );
        }

        $email = $render_data['email'] ?? null;

        return $email instanceof LoyaltyEmail && $email->id === $root_email->id ? $email->get_template_values() : [];
    }

    private function sample_values( LoyaltyEmail $root_email ): array {
        if ( null === $this->sample_values ) {
            // A clone: the shared mailer instance may be mid-send.
            $sample = clone $root_email;
            $sample->prepare_preview();
            $this->sample_values = $sample->get_template_values();
        }

        return $this->sample_values;
    }
}
