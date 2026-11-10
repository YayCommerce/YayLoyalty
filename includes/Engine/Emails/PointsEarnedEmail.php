<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;

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

    /** Awards with points left. Birthday awards have their own email, which takes them whether or not it is enabled. */
    protected function accepts( LedgerEntry $entry ): bool {
        return LedgerSource::BIRTHDAY !== $entry->get_source() && self::has_points_left( $entry );
    }

    /** @return array{points: string, points_label: string, balance: string} */
    protected function placeholder_values( LedgerEntry $entry ): array {
        return self::earned_amounts( $entry );
    }

    /** Previews have no real award, so they show a made-up one, greeting whoever is looking. */
    public function prepare_preview(): void {
        $this->prepare( new LedgerEntry( null, get_current_user_id(), 50, 150, LedgerType::EARN, LedgerSource::ORDER ) );
    }

    /** @return array<string, mixed> */
    protected function build_values( LedgerEntry $entry ): array {
        $order        = $entry->get_order_id() ? wc_get_order( $entry->get_order_id() ) : null;
        $order_number = $order ? (string) $order->get_order_number() : '';

        return $this->shared_values( $entry ) + [
            'earned_text'  => $this->earned_text( $entry, $order_number ),
            'order_number' => $order_number,
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
