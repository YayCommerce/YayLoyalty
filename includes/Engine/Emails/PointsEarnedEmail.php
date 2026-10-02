<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/** Points earned: sent when a customer earns points from an order or activity. */
class PointsEarnedEmail extends LoyaltyEmail {

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

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: You earned {points} {points_label}', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'You earned points', 'yayloyalty' );
    }

    /** Mails the customer about one awarded ledger row; no-op when disabled, the row is not a gain, or the user has no email. */
    public function trigger( LedgerEntry $entry ): void {
        if ( ! $this->is_enabled() || $entry->get_points() <= 0 ) {
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
                $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
            }
        } finally {
            // A throwing mail transport must not leave the rest of the request in the customer's locale.
            $this->restore_locale();
        }
    }

    /** Points the subject placeholders and template variables at $entry. */
    private function prepare( LedgerEntry $entry ): void {
        $this->object = $entry;

        $this->placeholders['{points}']       = number_format_i18n( $entry->get_points() );
        $this->placeholders['{points_label}'] = SettingsHelper::points_label( $entry->get_points() );
        $this->placeholders['{balance}']      = number_format_i18n( $entry->get_balance_after() );
    }

    /** WooCommerce's preview has no real award, so it shows a made-up one. */
    public function prepare_preview(): void {
        $this->prepare( new LedgerEntry( null, 0, 50, 150, LedgerType::EARN, LedgerSource::ORDER ) );
    }

    protected function extra_template_args( bool $plain_text ): array {
        if ( ! $this->object instanceof LedgerEntry ) {
            return [];
        }

        $entry = $this->object;

        return [
            'entry'         => $entry,
            'greeting_name' => self::greeting_name( $entry->get_user_id() ),
            'earned_text'   => $this->earned_text( $entry, $plain_text ),
            'balance_text'  => self::points_text( $entry->get_balance_after() ),
            'balance_value' => self::balance_value( $entry->get_balance_after(), $plain_text ),
            /**
             * Filters where the email's "View your points" link goes.
             *
             * @param string      $url   My Account by default.
             * @param LedgerEntry $entry The awarded row.
             */
            'cta_url'       => (string) apply_filters( 'yayloyalty_points_earned_cta_url', wc_get_page_permalink( 'myaccount' ), $entry ),
        ];
    }

    /** "50 Points", with the merchant's label. */
    private static function points_text( int $points ): string {
        return number_format_i18n( $points ) . ' ' . SettingsHelper::points_label( $points );
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
     * What the balance buys at the live discount rate, formatted for the store
     * currency; '' when the reward is not live or there is nothing to spend.
     */
    private static function balance_value( int $balance, bool $plain_text ): string {
        $amount = PointsMath::points_to_currency( $balance, ( new RewardRepository() )->live_points_per_currency_unit(), wc_get_price_decimals() );

        if ( null === $amount || $amount <= 0 ) {
            return '';
        }

        return $plain_text ? html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ) ) : wc_price( $amount );
    }

    /**
     * One whole sentence per source, so translators never assemble fragments.
     * The HTML version is escaped and bolds the points; the plain version is
     * raw text, because WooCommerce's plain-text cleanup deletes &#039;.
     */
    private function earned_text( LedgerEntry $entry, bool $plain_text ): string {
        $escape = $plain_text ? 'strval' : 'esc_html';
        $points = $escape( self::points_text( $entry->get_points() ) );

        if ( ! $plain_text ) {
            $points = '<strong>' . $points . '</strong>';
        }

        /* translators: %s: points with label, e.g. "50 Points". */
        $text = sprintf( $escape( __( 'You earned %s.', 'yayloyalty' ) ), $points );

        switch ( $entry->get_source() ) {
            case LedgerSource::ORDER:
                $order = $entry->get_order_id() ? wc_get_order( $entry->get_order_id() ) : null;

                if ( $order ) {
                    $text = sprintf(
                        /* translators: 1: points with label, e.g. "50 Points"; 2: order number. */
                        $escape( __( 'You earned %1$s for order #%2$s.', 'yayloyalty' ) ),
                        $points,
                        $escape( $order->get_order_number() )
                    );
                }
                break;

            case LedgerSource::SIGNUP:
                /* translators: %s: points with label, e.g. "50 Points". */
                $text = sprintf( $escape( __( 'You earned %s for creating an account.', 'yayloyalty' ) ), $points );
                break;

            case LedgerSource::REVIEW:
                /* translators: %s: points with label, e.g. "50 Points". */
                $text = sprintf( $escape( __( 'You earned %s for reviewing a product.', 'yayloyalty' ) ), $points );
                break;
        }

        /**
         * Filters the sentence telling the customer what they earned and why.
         * The HTML version is escaped HTML that may hold <strong>, printed through
         * wp_kses_post(); the plain version is raw text, escaped by the template.
         *
         * @param string      $text
         * @param LedgerEntry $entry      The awarded row.
         * @param bool        $plain_text Whether this is the plain-text email.
         */
        return (string) apply_filters( 'yayloyalty_points_earned_text', $text, $entry, $plain_text );
    }
}
