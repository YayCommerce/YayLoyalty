<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/** The points-expired email, sent after the expiry job writes an expire row, within the weekly cap. Off until the merchant turns it on. */
class PointsExpiredEmail extends LedgerEntryEmail {

    public const ID = 'yayloyalty_points_expired';

    protected $is_enabled_by_default = false;

    public function __construct() {
        $this->id          = self::ID;
        $this->title       = __( 'Points expired', 'yayloyalty' );
        $this->description = __( 'Sent after a customer\'s points balance has expired.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        $this->placeholders = [
            '{points}'       => '',
            '{points_label}' => '',
            '{balance}'      => '',
        ];

        add_action( Emails::POINTS_EXPIRED_ACTION . '_notification', [ $this, 'trigger' ] );

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Some of your {points_label} have expired', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your {points_label} have expired', 'yayloyalty' );
    }

    /** Expire rows only. */
    protected function accepts( LedgerEntry $entry ): bool {
        return LedgerType::EXPIRE === $entry->get_type();
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

    /** Previews have no real expiry, so they show a made-up one, greeting whoever is looking. */
    public function prepare_preview(): void {
        $this->prepare( new LedgerEntry( null, get_current_user_id(), -50, 0, LedgerType::EXPIRE, LedgerSource::EXPIRY ) );
    }

    /** @return array<string, mixed> */
    protected function build_values( LedgerEntry $entry ): array {
        return $this->balance_values( $entry );
    }
}
