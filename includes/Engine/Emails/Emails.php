<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Registers the Lite loyalty emails and their group with WooCommerce. */
class Emails {

    use SingletonTrait;

    public const GROUP_POINTS = 'yayloyalty_points';

    protected function __construct() {
        add_filter( 'woocommerce_email_classes', [ $this, 'add_classes' ] );
        add_filter( 'woocommerce_email_groups', [ $this, 'add_groups' ] );
        add_filter( 'woocommerce_email_actions', [ $this, 'add_email_actions' ] );
        add_filter( 'woocommerce_prepare_email_for_preview', [ $this, 'prepare_preview' ] );
    }

    /**
     * Has WooCommerce relay the events our emails send on: it builds the mailer before
     * firing {action}_notification, so each email's listener exists. Names no email
     * class: WooCommerce reads this on init, before it has loaded WC_Email.
     *
     * @param string[] $actions
     * @return string[]
     */
    public function add_email_actions( array $actions ): array {
        $actions[] = EarningService::AWARDED_ACTION;

        return $actions;
    }

    /**
     * Gives WooCommerce's settings preview loyalty sample data; it would otherwise
     * render a loyalty email around a dummy order, leaving {points} blank.
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
