<?php
namespace YayLoyalty\Pro\Emails;

use YayLoyalty\Engine\Emails\LoyaltyEmail;

defined( 'ABSPATH' ) || exit;

/** Base for Pro loyalty emails: templates live under includes/Pro/ so the Lite build strips them with the classes. */
abstract class ProLoyaltyEmail extends LoyaltyEmail {

    protected function templates_dir(): string {
        return __DIR__ . '/templates/';
    }
}
