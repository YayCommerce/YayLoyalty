<?php
namespace YayLoyalty;

use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Plugin entry point: boots Pro when present, then every component. */
class YayLoyalty {

    use SingletonTrait;

    protected function __construct() {
        \YayLoyalty\Engine\RestAPI::get_instance();
        \YayLoyalty\Engine\Register\RegisterFacade::get_instance();
        \YayLoyalty\Engine\Admin\Settings::get_instance();

        // The only reference to Pro in the Lite tree; do not add a second, use a seam (code-standards.md § 13).
        // Before rest_api_init and any campaign read, so Pro's filters are attached first.
        if ( self::is_pro() ) {
            \YayLoyalty\Pro\YayLoyaltyPro::boot();
        }
    }

    public static function initialize() {
        self::get_instance();
    }

    /** Whether this zip was built as Pro. Not a licence check, and never localised: the SPA is gated at build time. */
    public static function is_pro(): bool {
        return class_exists( 'YayLoyalty\Pro\YayLoyaltyPro', true );
    }
}
