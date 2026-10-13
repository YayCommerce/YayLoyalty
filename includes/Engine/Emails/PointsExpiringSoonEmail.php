<?php
namespace YayLoyalty\Engine\Emails;

defined( 'ABSPATH' ) || exit;

/** The points-expiring-soon email, which the expiry job relays for the `soon` reminder stage. */
class PointsExpiringSoonEmail extends LoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_points_expiring_soon';
        $this->title       = __( 'Points expiring soon', 'yayloyalty' );
        $this->description = __( 'Sent shortly before a customer\'s points balance expires.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        add_action( Emails::reminder_action( 'soon' ) . '_notification', [ $this, 'trigger' ] );

        parent::__construct();
    }

    public function trigger( int $user_id ): void {
        $this->trigger_for_user( $user_id );
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Your {points_label} expire soon', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your {points_label} expire soon', 'yayloyalty' );
    }
}
