<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** Reward claimed: sent when a customer claims a reward. */
class RewardClaimedEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_reward_claimed';
        $this->title       = __( 'Reward claimed', 'yayloyalty' );
        $this->description = __( 'Sent when a customer claims a reward.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_REWARDS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: You claimed a reward', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Reward claimed', 'yayloyalty' );
    }
}
