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

    public static function is_pro(): bool {
        return class_exists( 'YayLoyalty\Pro\YayLoyaltyPro', true );
    }
}
