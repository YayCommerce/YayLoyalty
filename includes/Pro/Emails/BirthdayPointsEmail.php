<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Engine\Emails\LedgerEntryEmail;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The birthday-points email, sent in place of Points earned for a birthday award. It shares Points earned's action
 * and takes only birthday rows; turning it off sends nothing for them. Templates live under includes/Pro/, as
 * ProLoyaltyEmail's do, so the Lite build strips them with the class.
 */
class BirthdayPointsEmail extends LedgerEntryEmail {

    public const ID = 'yayloyalty_birthday_points';

    public function __construct() {
        $this->id          = self::ID;
        $this->title       = __( 'Birthday points', 'yayloyalty' );
        $this->description = __( 'Sent when birthday points are awarded to a customer.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_BIRTHDAY;

        $this->placeholders = [
            '{points}'       => '',
            '{points_label}' => '',
            '{balance}'      => '',
        ];

        add_action( Emails::POINTS_EARNED_ACTION . '_notification', [ $this, 'trigger' ] );

        parent::__construct();
    }

    protected function templates_dir(): string {
        return __DIR__ . '/templates/';
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Happy birthday! Here are your points', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Happy birthday!', 'yayloyalty' );
    }

    /** Birthday awards with points left, so a later clawback never sends a stale email. */
    protected function accepts( LedgerEntry $entry ): bool {
        return LedgerSource::BIRTHDAY === $entry->get_source()
            && $entry->get_points() > 0
            && ( new LedgerRepository() )->sum_refunded_for( $entry, false ) < $entry->get_points();
    }

    /** @return array{points: string, points_label: string, balance: string} */
    protected function amounts( LedgerEntry $entry ): array {
        return [
            'points'       => SettingsHelper::format_number( $entry->get_points() ),
            'points_label' => SettingsHelper::points_label( $entry->get_points() ),
            'balance'      => SettingsHelper::format_number( $entry->get_balance_after() ),
        ];
    }

    /** Previews have no real award, so they show a made-up one, greeting whoever is looking. */
    public function prepare_preview(): void {
        $this->prepare( new LedgerEntry( null, get_current_user_id(), 150, 300, LedgerType::EARN, LedgerSource::BIRTHDAY ) );
    }

    /** @return array<string, mixed> */
    protected function build_values( LedgerEntry $entry ): array {
        return $this->balance_values( $entry ) + [
            'birthday_text' => $this->birthday_text( $entry ),
        ];
    }

    /** Escaped HTML with the points in bold. */
    private function birthday_text( LedgerEntry $entry ): string {
        $points = '<strong>' . esc_html( SettingsHelper::points_text( $entry->get_points() ) ) . '</strong>';

        /* translators: %s: points with label, e.g. "150 Points". */
        $text = sprintf( esc_html__( 'To celebrate your birthday, we added %s to your account.', 'yayloyalty' ), $points );

        /**
         * Filters the sentence telling the customer about their birthday points. It is escaped HTML,
         * printed through wp_kses_post(). The plain-text email strips its tags.
         *
         * @param string      $text
         * @param LedgerEntry $entry The awarded row.
         */
        return (string) apply_filters( 'yayloyalty_birthday_points_text', $text, $entry );
    }
}
