<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Emails\Emails;

defined( 'ABSPATH' ) || exit;

/** The last-chance points-expiry email. Nothing triggers it yet. */
class ExpiringFinalReminderEmail extends ProLoyaltyEmail {

    protected $is_enabled_by_default = false;

    public function __construct() {
        $this->id          = 'yayloyalty_expiring_final_reminder';
        $this->title       = __( 'Expiring final reminder', 'yayloyalty' );
        $this->description = __( 'A last-chance reminder sent just before points expire.', 'yayloyalty' );
        $this->email_group = Emails::GROUP_POINTS;

        parent::__construct();
    }

    public function get_default_subject() {
        return __( '[{site_title}]: Last chance to use your points', 'yayloyalty' );
    }

    public function get_default_heading() {
        return __( 'Last chance to use your points', 'yayloyalty' );
    }
}
