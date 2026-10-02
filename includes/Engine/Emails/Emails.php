<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Helpers\EmailsHelper;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Registers the Lite loyalty emails and their group with WooCommerce. */
class Emails {

    use SingletonTrait;

    public const GROUP_POINTS = 'yayloyalty_points';

    protected function __construct() {
        add_filter( 'woocommerce_email_classes', [ $this, 'add_classes' ] );
        add_filter( 'woocommerce_email_groups', [ $this, 'add_groups' ] );
        add_action( 'yayloyalty_points_awarded', [ $this, 'send_points_earned' ] );
        add_filter( 'woocommerce_prepare_email_for_preview', [ $this, 'prepare_preview' ] );
    }

    /** Sends the Points earned email for one award, synchronously: the hook runs after commit, so a mail failure never touches the award. */
    public function send_points_earned( Outcome $awarded ): void {
        $entry = $awarded->get_entry();

        $email = EmailsHelper::registered_emails()[ PointsEarnedEmail::ID ] ?? null;

        if ( $entry && $email instanceof PointsEarnedEmail ) {
            $email->trigger( $entry );
        }
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
