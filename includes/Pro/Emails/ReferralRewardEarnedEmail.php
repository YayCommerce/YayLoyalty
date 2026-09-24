<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** Referral reward earned: sent when a referral earns a reward for either party. */
class ReferralRewardEarnedEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_referral_reward_earned';
        $this->title       = __( 'Referral reward earned', 'yayloyalty' );
        $this->description = __( 'Sent when a referral earns a reward for either party.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_REFERRAL;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: You earned a referral reward', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'You earned a referral reward', 'yayloyalty' );
    }
}
