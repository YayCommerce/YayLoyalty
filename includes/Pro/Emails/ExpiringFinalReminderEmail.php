<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Services\ExpiryRules;

defined( 'ABSPATH' ) || exit;

/** The last-chance points-expiry email, which the expiry job relays for Pro's `final` reminder stage. */
class ExpiringFinalReminderEmail extends ProLoyaltyEmail {

    public function __construct() {
        $this->id          = 'yayloyalty_expiring_final_reminder';
        $this->title       = __( 'Expiring final reminder', 'yayloyalty' );
        $this->description = __( 'A last-chance reminder sent just before points expire.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        add_action( Emails::reminder_action( ExpiryRules::STAGE_FINAL ) . '_notification', [ $this, 'trigger_for_user' ] );

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Last chance to use your points', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Last chance to use your points', 'yayloyalty' );
    }
}
