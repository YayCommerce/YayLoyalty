<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Controllers\SettingsRestController;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestAPI
 *
 * Handles YayLoyalty REST API endpoints.
 */
class RestAPI {
    use SingletonTrait;

    protected function __construct() {
        add_action( 'rest_api_init', [ $this, 'loyalty_endpoints' ] );
    }

    public function loyalty_endpoints() {
        SettingsRestController::get_instance();
    }
}
