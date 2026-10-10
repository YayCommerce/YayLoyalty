<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/** The points-earned email, sent after each award commits, within the weekly cap. */
class PointsEarnedEmail extends LedgerEntryEmail {

    public const ID = 'yayloyalty_points_earned';

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

    /** Awards with points left: a deferred send can run after a refund or cancellation took them back. */
    protected function accepts( LedgerEntry $entry ): bool {
        return $entry->get_points() > 0
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
        $this->prepare( new LedgerEntry( null, get_current_user_id(), 50, 150, LedgerType::EARN, LedgerSource::ORDER ) );
    }

    /** Every value the email shows for its current award, for other renderers such as YayMail. [] until one is prepared. */
    public function get_template_values(): array {
        return $this->extra_template_args();
    }

    /** @return array<string, mixed> */
    protected function build_values( LedgerEntry $entry ): array {
        $order        = $entry->get_order_id() ? wc_get_order( $entry->get_order_id() ) : null;
        $order_number = $order ? (string) $order->get_order_number() : '';

        $greeting_name = self::greeting_name( $entry->get_user_id() );
        $balance_text  = SettingsHelper::points_text( $entry->get_balance_after() );
        $balance_value = self::balance_value( $entry->get_balance_after() );

        return $this->amounts( $entry ) + [
            'entry'              => $entry,
            'greeting_name'      => $greeting_name,
            'greeting_text'      => self::greeting_text( esc_html( $greeting_name ) ),
            'earned_text'        => $this->earned_text( $entry, $order_number ),
            'balance_text'       => $balance_text,
            'balance_sentence'   => self::balance_sentence( esc_html( $balance_text ) ),
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
