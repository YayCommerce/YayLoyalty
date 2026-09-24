<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** Tier lost: sent when a customer drops out of their current tier. */
class TierLostEmail extends ProLoyaltyEmail {

    protected $is_enabled_by_default = false;

    public function __construct() {
        $this->id          = 'yayloyalty_tier_lost';
        $this->title       = __( 'Tier lost', 'yayloyalty' );
        $this->description = __( 'Sent when a customer drops out of their current tier.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_TIERS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Your tier has changed', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your tier has changed', 'yayloyalty' );
    }
}
