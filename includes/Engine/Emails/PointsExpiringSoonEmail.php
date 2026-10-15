<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\CustomerActivityService;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The points-expiring-soon email, sent once per reminder the expiry job claims (Engine\Expiry\ExpiryReminders).
 * Uncapped: it is the one email that can still save a balance, so the weekly cap never stops it, though it counts.
 */
class PointsExpiringSoonEmail extends LoyaltyEmail {

    public const ID = 'yayloyalty_points_expiring_soon';

    protected $is_capped = false;

    /** @var array{user_id: int, points: int, expires_at: string, is_single_date: bool}|null The reminder being sent or previewed. */
    private $reminder = null;

    public function __construct() {
        $this->id          = self::ID;
        $this->title       = __( 'Points expiring soon', 'yayloyalty' );
        $this->description = __( 'Sent before a customer\'s points expire, as many days ahead as the Expiry tab sets.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        $this->placeholders = [
            '{points}'       => '',
            '{points_label}' => '',
            '{expiry_date}'  => '',
        ];

        add_action( Emails::POINTS_EXPIRING_SOON_ACTION . '_notification', [ $this, 'trigger' ], 10, 4 );

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Your {points_label} expire soon', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your {points_label} expire soon', 'yayloyalty' );
    }

    /** Mails the reminder the expiry job claimed, and logs it in the customer's activity once the transport accepts it. */
    public function trigger( int $user_id, int $points, string $expires_at, bool $is_single_date ): void {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return;
        }

        // is_enabled()'s filter receives $this->object.
        $this->object = $user;

        if ( ! $this->is_enabled() ) {
            return;
        }

        // Before the placeholders: the default point labels are translated strings.
        $this->setup_locale();

        try {
            $this->prepare( $user_id, $points, $expires_at, $is_single_date );
            $this->recipient = $user->user_email;

            if ( $this->get_recipient() && $this->send_to_customer( $user_id ) ) {
                ( new CustomerActivityService( new CustomerMetaRepository(), new CustomerRepository(), new LedgerRepository() ) )
                    ->record_expiring_soon_email_sent( $user_id, $points, $expires_at, ExpiryPolicy::current()->reminder_days() );
            }
        } finally {
            // A throwing mail transport must not leave the rest of the request in the customer's locale.
            $this->restore_locale();
        }
    }

    /** Previews have no real reminder, so they show a made-up one, greeting whoever is looking. */
    public function prepare_preview(): void {
        $this->prepare( get_current_user_id(), 120, Dates::offset( 30 * DAY_IN_SECONDS ), true );
    }

    /** Points the placeholders and template values at one reminder. */
    private function prepare( int $user_id, int $points, string $expires_at, bool $is_single_date ): void {
        $this->reminder = [
            'user_id'        => $user_id,
            'points'         => $points,
            'expires_at'     => $expires_at,
            'is_single_date' => $is_single_date,
        ];

        $this->placeholders['{points}']       = SettingsHelper::format_number( $points );
        $this->placeholders['{points_label}'] = SettingsHelper::points_label( $points );
        $this->placeholders['{expiry_date}']  = Dates::to_site_date( $expires_at );
    }

    /** @return array<string, mixed> [] until a reminder is prepared, so the templates fall back. */
    protected function extra_template_args(): array {
        if ( null === $this->reminder ) {
            return [];
        }

        $user_id       = $this->reminder['user_id'];
        $points        = $this->reminder['points'];
        $expiry_date   = Dates::to_site_date( $this->reminder['expires_at'] );
        $greeting_name = self::greeting_name( $user_id );
        $points_value  = self::balance_value( $points );
        $is_batch      = ExpiryPolicy::current()->is_batch();

        return [
            'greeting_name'     => $greeting_name,
            'greeting_text'     => self::greeting_text( esc_html( $greeting_name ) ),
            'points'            => SettingsHelper::format_number( $points ),
            'points_label'      => SettingsHelper::points_label( $points ),
            'expiry_date'       => $expiry_date,
            'expiring_text'     => self::expiring_text( $points, $expiry_date, $is_batch && ! $this->reminder['is_single_date'] ),
            'keep_text'         => $is_batch ? '' : esc_html__( 'Earn or redeem before then to keep them.', 'yayloyalty' ),
            'points_value'      => $points_value,
            'points_value_text' => self::balance_value_text( $points_value ),
            /**
             * Filters where the email's "Use your points" link goes. '' hides the button.
             *
             * @param string $url     '' by default: the plugin has no customer points page yet.
             * @param int    $user_id The customer being reminded.
             */
            'cta_url'           => (string) apply_filters( 'yayloyalty_points_expiring_soon_cta_url', '', $user_id ),
        ];
    }

    /** Escaped HTML with the points in bold. One whole sentence per case, so translators never assemble fragments. */
    private static function expiring_text( int $points, string $expiry_date, bool $has_later_dates ): string {
        $points_html = '<strong>' . esc_html( SettingsHelper::points_text( $points ) ) . '</strong>';

        if ( $has_later_dates ) {
            /* translators: 1: points with label, e.g. "120 Points"; 2: the first expiry date. */
            return sprintf( esc_html__( '%1$s will expire, starting %2$s.', 'yayloyalty' ), $points_html, esc_html( $expiry_date ) );
        }

        /* translators: 1: points with label, e.g. "120 Points"; 2: the expiry date. */
        return sprintf( esc_html__( '%1$s will expire on %2$s.', 'yayloyalty' ), $points_html, esc_html( $expiry_date ) );
    }
}
