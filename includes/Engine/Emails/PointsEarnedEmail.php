<?php
namespace YayLoyalty\Engine\Emails;

defined( 'ABSPATH' ) || exit;

/** Points earned: sent when a customer earns points from an order or activity. */
class PointsEarnedEmail extends LoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_points_earned';
        $this->title       = __( 'Points earned', 'yayloyalty' );
        $this->description = __( 'Sent when a customer earns points from an order or activity.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: You earned loyalty points', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'You earned points', 'yayloyalty' );
    }
}
