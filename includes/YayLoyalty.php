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
