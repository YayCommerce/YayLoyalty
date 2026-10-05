<?php
namespace YayLoyalty\Engine\Emails;

defined( 'ABSPATH' ) || exit;

/** The points-expired email. Nothing triggers it yet. */
class PointsExpiredEmail extends LoyaltyEmail {

    protected $is_enabled_by_default = false;

    public function __construct() {
        $this->id          = 'yayloyalty_points_expired';
        $this->title       = __( 'Points expired', 'yayloyalty' );
        $this->description = __( 'Sent after a customer\'s points balance has expired.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Some of your points have expired', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your points have expired', 'yayloyalty' );
    }
}
