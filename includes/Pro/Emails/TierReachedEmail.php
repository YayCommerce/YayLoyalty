<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** Tier reached: sent when a customer reaches a new tier. */
class TierReachedEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_tier_reached';
        $this->title       = __( 'Tier reached', 'yayloyalty' );
        $this->description = __( 'Sent when a customer reaches a new tier.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_TIERS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Welcome to your new tier', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'You reached a new tier', 'yayloyalty' );
    }
}
