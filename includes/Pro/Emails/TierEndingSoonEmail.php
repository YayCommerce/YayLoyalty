<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** The tier-ending-soon email. Nothing triggers it yet. */
class TierEndingSoonEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_tier_ending_soon';
        $this->title       = __( 'Tier ending soon', 'yayloyalty' );
        $this->description = __( 'Sent shortly before a customer\'s current tier expires.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_TIERS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Your tier ends soon', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your tier ends soon', 'yayloyalty' );
    }
}
