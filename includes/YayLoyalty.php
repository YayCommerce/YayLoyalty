<?php
namespace YayLoyalty;

use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * YayLoyalty Plugin Initializer
 */
class YayLoyalty {

    use SingletonTrait;

    protected function __construct() {
        \YayLoyalty\Engine\RestAPI::get_instance();
        \YayLoyalty\Engine\Register\RegisterFacade::get_instance();
        \YayLoyalty\Engine\Admin\Settings::get_instance();

        /*
         * THE ONLY REFERENCE TO PREMIUM IN THE LITE TREE.
         *
         * Valid in both builds: the Lite build deletes includes/Premium/, so
         * the class does not exist, the branch is false, and nothing happens.
         * That is what makes the Lite build a directory deletion instead of
         * marker surgery on shared files.
         *
         * Premium boots BEFORE rest_api_init and before any campaign is read,
         * so its filters are attached by the time the seams are consulted.
         *
         * Do not add a second reference. If Premium needs something from Lite,
         * it takes it through a seam — see docs/code-standards.md section 13.
         */
        if ( self::is_premium() ) {
            \YayLoyalty\Premium\YayLoyaltyPremium::boot();
        }
    }

    public static function initialize() {
        self::get_instance();
    }

    /**
     * Is Premium code present in this build?
     *
     * This asks one question only: was this zip built as Premium? It is NOT a
     * licence check. An expired licence stops updates and support, not
     * features, so nothing here consults licence state.
     *
     * Server-side only. The SPA is gated at build time by the __IS_PREMIUM__
     * Vite define, so this value is deliberately never localized to the
     * browser — see docs/code-standards.md.
     */
    public static function is_premium(): bool {
        return class_exists( 'YayLoyalty\Premium\YayLoyaltyPremium', true );
    }
}
