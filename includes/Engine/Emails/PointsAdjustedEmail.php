<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/** The points-adjusted email, sent after a manual adjustment whose admin asked to notify the customer, within the weekly cap. */
class PointsAdjustedEmail extends LedgerEntryEmail {

    public const ID = 'yayloyalty_points_adjusted';

    public function __construct() {
        $this->id          = self::ID;
        $this->title       = __( 'Points adjusted', 'yayloyalty' );
        $this->description = __( 'Sent when a store manager adjusts a customer\'s points and asks to notify them.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        $this->placeholders = [
            '{points}'       => '',
            '{points_label}' => '',
            '{balance}'      => '',
        ];

        add_action( Emails::POINTS_ADJUSTED_ACTION . '_notification', [ $this, 'trigger' ] );

        parent::__construct();
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
    protected function amounts( LedgerEntry $entry ): array {
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
        return $this->balance_values( $entry ) + [
            'adjusted_text' => self::adjusted_text( $entry ),
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
