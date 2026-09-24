<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Registers the Lite loyalty emails and their group with WooCommerce. */
class Emails {

    use SingletonTrait;

    public const GROUP_POINTS = 'yayloyalty_points';

    protected function __construct() {
        add_filter( 'woocommerce_email_classes', [ $this, 'add_classes' ] );
        add_filter( 'woocommerce_email_groups', [ $this, 'add_groups' ] );
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
