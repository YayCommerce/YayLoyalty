<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/** The points-earned email, sent after each award commits, within the weekly cap. */
class PointsEarnedEmail extends LoyaltyEmail {

    public const ID = 'yayloyalty_points_earned';

    /** @var LedgerEntry|null The award $values were built for. */
    private $values_entry = null;

    /** @var array<string, mixed> */
    private $values = [];

    public function __construct() {
        $this->id          = self::ID;
        $this->title       = __( 'Points earned', 'yayloyalty' );
        $this->description = __( 'Sent when a customer earns points from an order or activity.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        $this->placeholders = [
            '{points}'       => '',
            '{points_label}' => '',
            '{balance}'      => '',
        ];

        add_action( Emails::POINTS_EARNED_ACTION . '_notification', [ $this, 'trigger' ] );

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: You earned {points} {points_label}', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'You earned {points_label}', 'yayloyalty' );
    }

    /** Mails the customer about one award, which Emails::relay_award() passes as its ledger row id. */
    public function trigger( int $entry_id ): void {
        $ledger = new LedgerRepository();
        $entry  = $ledger->find( $entry_id );

        if ( ! $entry instanceof LedgerEntry || $entry->get_points() <= 0 ) {
            return;
        }

        // is_enabled()'s filter receives $this->object, which must be this award.
        $this->object = $entry;

        if ( ! $this->is_enabled() ) {
            return;
        }

        // A deferred send can run after a refund or cancellation took the points back.
        if ( $ledger->sum_refunded_for( $entry, false ) >= $entry->get_points() ) {
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

    /** Points the subject placeholders and template variables at $entry. */
    private function prepare( LedgerEntry $entry ): void {
        $this->object = $entry;

        foreach ( self::amounts( $entry ) as $key => $value ) {
            $this->placeholders[ '{' . $key . '}' ] = $value;
        }
    }

    /** @return array{points: string, points_label: string, balance: string} The subject placeholders' values, which the body shows too. */
    private static function amounts( LedgerEntry $entry ): array {
        return [
            'points'       => SettingsHelper::format_number( $entry->get_points() ),
            'points_label' => SettingsHelper::points_label( $entry->get_points() ),
            'balance'      => SettingsHelper::format_number( $entry->get_balance_after() ),
        ];
    }

    /** Previews have no real award, so they show a made-up one, greeting whoever is looking. */
    public function prepare_preview(): void {
        $this->prepare( new LedgerEntry( null, get_current_user_id(), 50, 150, LedgerType::EARN, LedgerSource::ORDER ) );
    }

    /** Every value the email shows for its current award, for other renderers such as YayMail. [] until one is prepared. */
    public function get_template_values(): array {
        return $this->extra_template_args();
    }

    /** Built once per award: a multipart send and YayMail each render it again. */
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
        $order        = $entry->get_order_id() ? wc_get_order( $entry->get_order_id() ) : null;
        $order_number = $order ? (string) $order->get_order_number() : '';

        $balance_value = self::balance_value( $entry->get_balance_after() );

        return self::amounts( $entry ) + [
            'entry'              => $entry,
            'greeting_name'      => self::greeting_name( $entry->get_user_id() ),
            'earned_text'        => $this->earned_text( $entry, $order_number ),
            'balance_text'       => SettingsHelper::points_text( $entry->get_balance_after() ),
            'balance_value'      => $balance_value,
            'balance_value_text' => self::balance_value_text( $balance_value ),
            'order_number'       => $order_number,
            /**
             * Filters where the email's "View your points" link goes. '' hides the button.
             *
             * @param string      $url   '' by default: the plugin has no customer points page yet.
             * @param LedgerEntry $entry The awarded row.
             */
            'cta_url'            => (string) apply_filters( 'yayloyalty_points_earned_cta_url', '', $entry ),
        ];
    }

    /** First name, else display name, else '' (the template then drops the greeting). */
    private static function greeting_name( int $user_id ): string {
        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return '';
        }

        return $user->first_name ? $user->first_name : $user->display_name;
    }

    /**
     * What the balance buys at the live discount rate, as wc_price() HTML.
     * '' when the reward is not live or there is nothing to spend.
     */
    private static function balance_value( int $balance ): string {
        $amount = PointsMath::points_to_currency( $balance, ( new RewardRepository() )->live_points_per_currency_unit(), wc_get_price_decimals() );

        if ( null === $amount || $amount <= 0 ) {
            return '';
        }

        // The rate is in the store's base currency: a currency switcher's session currency would mislabel it.
        return wc_price( $amount, [ 'currency' => get_option( 'woocommerce_currency' ) ] );
    }

    /** "That's worth <strong>$1.50</strong> in rewards." around balance_value(), or '' when that is ''. */
    private static function balance_value_text( string $balance_value ): string {
        /* translators: %s: money amount, e.g. "$1.50". */
        return '' === $balance_value ? '' : sprintf( esc_html__( 'That\'s worth %s in rewards.', 'yayloyalty' ), '<strong>' . $balance_value . '</strong>' );
    }

    /** Escaped HTML with the points in bold. One whole sentence per source, so translators never assemble fragments. */
    private function earned_text( LedgerEntry $entry, string $order_number ): string {
        /* translators: %s: points with label, e.g. "50 Points". */
        $format = __( 'You earned %s.', 'yayloyalty' );
        $extra  = [];

        switch ( $entry->get_source() ) {
            case LedgerSource::ORDER:
                if ( '' !== $order_number ) {
                    /* translators: 1: points with label, e.g. "50 Points"; 2: order number. */
                    $format = __( 'You earned %1$s for order #%2$s.', 'yayloyalty' );
                    $extra  = [ $order_number ];
                }
                break;

            case LedgerSource::SIGNUP:
                /* translators: %s: points with label, e.g. "50 Points". */
                $format = __( 'You earned %s for creating an account.', 'yayloyalty' );
                break;

            case LedgerSource::REVIEW:
                /* translators: %s: points with label, e.g. "50 Points". */
                $format = __( 'You earned %s for reviewing a product.', 'yayloyalty' );
                break;
        }

        $points = '<strong>' . esc_html( SettingsHelper::points_text( $entry->get_points() ) ) . '</strong>';
        $text   = vsprintf( esc_html( $format ), array_merge( [ $points ], array_map( 'esc_html', $extra ) ) );

        /**
         * Filters the sentence telling the customer what they earned and why. It is escaped HTML,
         * printed through wp_kses_post(). The plain-text email strips its tags.
         *
         * @param string      $text
         * @param LedgerEntry $entry The awarded row.
         */
        return (string) apply_filters( 'yayloyalty_points_earned_text', $text, $entry );
    }
}
