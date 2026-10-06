<?php
namespace YayLoyalty\Pro\Emails;

defined( 'ABSPATH' ) || exit;

/** The invite-sent email. Nothing triggers it yet. */
class InviteSentEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_invite_sent';
        $this->title       = __( 'Invite sent', 'yayloyalty' );
        $this->description = __( 'Sent when a customer invites a friend to join.', 'yayloyalty' );
        $this->email_group = ProEmails::GROUP_REFERRAL;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Your invite is on its way', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Your invite is on its way', 'yayloyalty' );
    }
}
