<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Registers the Lite loyalty emails and their group with WooCommerce. */
class Emails {

    use SingletonTrait;

    public const GROUP_POINTS = 'yayloyalty_points';

    /** PointsEarnedEmail's id, kept here so this class can read its settings without loading WC_Email. */
    public const POINTS_EARNED_ID = 'yayloyalty_points_earned';

    /** The email action an award is relayed on. Its only argument is the ledger row id. */
    public const POINTS_EARNED_ACTION = 'yayloyalty_points_earned_email';

    protected function __construct() {
        add_filter( 'woocommerce_email_classes', [ $this, 'add_classes' ] );
        add_filter( 'woocommerce_email_groups', [ $this, 'add_groups' ] );
        add_filter( 'woocommerce_email_actions', [ $this, 'add_email_actions' ] );
        add_action( EarningService::AWARDED_ACTION, [ $this, 'relay_award' ] );
        add_filter( 'woocommerce_prepare_email_for_preview', [ $this, 'prepare_preview' ] );
    }

    /**
     * WooCommerce builds the mailer before it fires {action}_notification, so each email's listener exists.
     * No email class is named here: WooCommerce reads this list on init, before WC_Email loads.
     *
     * @param string[] $actions
     * @return string[]
     */
    public function add_email_actions( array $actions ): array {
        $actions[] = self::POINTS_EARNED_ACTION;

        return $actions;
    }

    /**
     * Passes an award to the Points earned email as its ledger row id: with deferred emails, WooCommerce
     * before 10.8 serializes an action's arguments, and the award's Context may hold a Closure.
     * Skipped while the email is off, which also spares building WooCommerce's mailer.
     */
    public function relay_award( Outcome $awarded ): void {
        $entry = $awarded->get_entry();
        // WC_Email's own settings option, read directly because WC_Email cannot load here.
        // Unlike is_enabled(), this skips the woocommerce_email_enabled_{id} filter.
        $settings = get_option( 'woocommerce_' . self::POINTS_EARNED_ID . '_settings' );

        if ( ! $entry || null === $entry->get_id() || $entry->get_points() <= 0 ) {
            return;
        }

        if ( is_array( $settings ) && 'no' === ( $settings['enabled'] ?? '' ) ) {
            return;
        }

        /**
         * Fires once per award the Points earned email should report. WooCommerce relays it to
         * {action}_notification, at once or at the end of the request.
         *
         * @param int $entry_id The awarded ledger row.
         */
        do_action( self::POINTS_EARNED_ACTION, $entry->get_id() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
    }

    /**
     * Gives WooCommerce's settings preview loyalty sample data. Otherwise it renders a loyalty email
     * around a dummy order, with {points} blank.
     *
     * @param \WC_Email $email
     * @return \WC_Email
     */
    public function prepare_preview( $email ) {
        if ( $email instanceof LoyaltyEmail ) {
            $email->prepare_preview();
        }

        return $email;
    }

    /**
     * @param array<string, \WC_Email> $classes
     * @return array<string, \WC_Email>
     */
    public function add_classes( array $classes ): array {
        return self::add( $classes, new PointsEarnedEmail(), new PointsExpiringSoonEmail(), new PointsExpiredEmail() );
    }

    /**
     * Adds loyalty emails to WooCommerce's list, keyed by id: WooCommerce opens an
     * email's settings screen at section=<key>, so the id is also that section.
     *
     * @param array<string, \WC_Email> $classes
     * @return array<string, \WC_Email>
     */
    public static function add( array $classes, LoyaltyEmail ...$emails ): array {
        foreach ( $emails as $email ) {
            $classes[ $email->id ] = $email;
        }

        return $classes;
    }

    /**
     * @param array<string, string> $groups
     * @return array<string, string>
     */
    public function add_groups( array $groups ): array {
        $groups[ self::GROUP_POINTS ] = __( 'Loyalty: Points', 'yayloyalty' );

        return $groups;
    }
}
