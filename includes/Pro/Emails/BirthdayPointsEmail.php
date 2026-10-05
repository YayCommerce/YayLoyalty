<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** The birthday-points email. Nothing triggers it yet. */
class BirthdayPointsEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_birthday_points';
        $this->title       = __( 'Birthday points', 'yayloyalty' );
        $this->description = __( 'Sent when birthday points are awarded to a customer.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_BIRTHDAY;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Happy birthday! Here are your points', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Happy birthday!', 'yayloyalty' );
    }
}
