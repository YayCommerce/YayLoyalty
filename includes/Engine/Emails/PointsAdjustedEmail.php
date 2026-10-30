<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The points-adjusted email, sent after a manual adjustment whose admin asked to notify the customer, within the weekly cap.
 * Manual in WooCommerce's sense: that per-adjustment choice is the only on/off, so it has no `enabled` setting.
 */
class PointsAdjustedEmail extends LedgerEntryEmail {

    public const ID = 'yayloyalty_points_adjusted';

    public function __construct() {
        $this->id          = self::ID;
        $this->title       = __( 'Points adjusted', 'yayloyalty' );
        $this->description = __( 'Sent when a store manager adjusts a customer\'s points and ticks Notify by email.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;
        $this->manual      = true;

        $this->placeholders = [
            '{points}'       => '',
            '{points_label}' => '',
            '{balance}'      => '',
        ];

        add_action( Emails::POINTS_ADJUSTED_ACTION . '_notification', [ $this, 'trigger' ] );

        parent::__construct();
    }

    public function init_form_fields() {
        parent::init_form_fields();

        unset( $this->form_fields['enabled'] );
    }

    /** Always on, ignoring an `enabled = no` saved before it became manual. Keeps WooCommerce's filter. */
    public function is_enabled() {
        return apply_filters( 'woocommerce_email_enabled_' . $this->id, true, $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own hook.
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Your {points_label} balance was updated', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your {points_label} balance was updated', 'yayloyalty' );
    }

    /** Manual adjustments only. */
    protected function accepts( LedgerEntry $entry ): bool {
        return LedgerSource::MANUAL === $entry->get_source() && LedgerType::is_manual_adjustment( $entry->get_type() );
    }

    /** @return array{points: string, points_label: string, balance: string} */
    protected function placeholder_values( LedgerEntry $entry ): array {
        $points = abs( $entry->get_points() );

        return [
            'points'       => SettingsHelper::format_number( $points ),
            'points_label' => SettingsHelper::points_label( $points ),
            'balance'      => SettingsHelper::format_number( $entry->get_balance_after() ),
        ];
    }

    /** Previews have no real adjustment, so they show a made-up one, greeting whoever is looking. */
    public function prepare_preview(): void {
        $this->prepare( new LedgerEntry( null, get_current_user_id(), 50, 150, LedgerType::ADJUST, LedgerSource::MANUAL ) );
    }

    /** @return array<string, mixed> */
    protected function build_values( LedgerEntry $entry ): array {
        return $this->shared_values( $entry ) + [
            'adjusted_text' => self::adjusted_text( $entry ),
            // sanitize_text_field() stores a bare "<" as "&lt;"; the templates escape it once more.
            'customer_note' => Text::plain( (string) $entry->get_customer_note() ),
        ];
    }

    /** Escaped HTML with the points in bold, one whole sentence per direction. */
    private static function adjusted_text( LedgerEntry $entry ): string {
        $format = $entry->get_points() > 0
            /* translators: %s: points with label, e.g. "50 Points". */
            ? __( 'We added %s to your account.', 'yayloyalty' )
            /* translators: %s: points with label, e.g. "50 Points". */
            : __( 'We removed %s from your account.', 'yayloyalty' );

        $points = '<strong>' . esc_html( SettingsHelper::points_text( abs( $entry->get_points() ) ) ) . '</strong>';

        return sprintf( esc_html( $format ), $points );
    }
}
