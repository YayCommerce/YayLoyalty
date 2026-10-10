<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/** The points-adjusted email, sent after a manual adjustment whose admin asked to notify the customer, within the weekly cap. */
class PointsAdjustedEmail extends LoyaltyEmail {

    public const ID = 'yayloyalty_points_adjusted';

    /** @var LedgerEntry|null The adjustment $values were built for. */
    private $values_entry = null;

    /** @var array<string, mixed> */
    private $values = [];

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

    /** Mails the customer about one manual adjustment, which Emails::relay_adjustment() passes as its ledger row id. */
    public function trigger( int $entry_id ): void {
        $entry = ( new LedgerRepository() )->find( $entry_id );

        if ( ! $entry instanceof LedgerEntry || ! self::is_adjustment( $entry ) ) {
            return;
        }

        // is_enabled()'s filter receives $this->object, which must be this adjustment.
        $this->object = $entry;

        if ( ! $this->is_enabled() ) {
            return;
        }

        $user = get_userdata( $entry->get_user_id() );

        if ( ! $user ) {
            return;
        }

        // Before the placeholders: the default point labels are translated strings.
        $this->setup_locale();

        try {
            $this->prepare( $entry );
            $this->recipient = $user->user_email;

            if ( $this->get_recipient() ) {
                $this->send_to_customer( $entry->get_user_id() );
            }
        } finally {
            // A throwing mail transport must not leave the rest of the request in the customer's locale.
            $this->restore_locale();
        }
    }

    private static function is_adjustment( LedgerEntry $entry ): bool {
        return LedgerSource::MANUAL === $entry->get_source()
            && in_array( $entry->get_type(), [ LedgerType::ADJUST, LedgerType::CORRECTION ], true )
            && 0 !== $entry->get_points();
    }

    /** Points the subject placeholders and template variables at $entry. */
    private function prepare( LedgerEntry $entry ): void {
        $this->object = $entry;

        foreach ( self::amounts( $entry ) as $key => $value ) {
            $this->placeholders[ '{' . $key . '}' ] = $value;
        }
    }

    /** @return array{points: string, points_label: string, balance: string} The subject placeholders' values, which the body shows too. */
    private static function amounts( LedgerEntry $entry ): array {
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

    /** Built once per adjustment: a multipart send renders it twice. */
    protected function extra_template_args(): array {
        if ( ! $this->object instanceof LedgerEntry ) {
            return [];
        }

        if ( $this->values_entry !== $this->object ) {
            $this->values_entry = $this->object;
            $this->values       = $this->build_values( $this->object );
        }

        return $this->values;
    }

    /** @return array<string, mixed> */
    private function build_values( LedgerEntry $entry ): array {
        $balance_value = RedemptionService::is_available() ? self::balance_value( $entry->get_balance_after() ) : '';

        return self::amounts( $entry ) + [
            'entry'              => $entry,
            'greeting_name'      => self::greeting_name( $entry->get_user_id() ),
            'adjusted_text'      => self::adjusted_text( $entry ),
            'balance_text'       => SettingsHelper::points_text( $entry->get_balance_after() ),
            'balance_value'      => $balance_value,
            'balance_value_text' => self::balance_value_text( $balance_value ),
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
