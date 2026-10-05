<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** The coupon-issued email. Nothing triggers it yet. */
class CouponIssuedEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_coupon_issued';
        $this->title       = __( 'Coupon issued', 'yayloyalty' );
        $this->description = __( 'Sent when a reward coupon is issued to a customer.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_REWARDS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: You have a new reward coupon', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'You have a new reward coupon', 'yayloyalty' );
    }
}
