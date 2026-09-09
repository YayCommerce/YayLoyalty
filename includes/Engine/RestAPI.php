<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\SettingsRestController;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Class RestAPI
 *
 * Handles YayLoyalty REST API endpoints.
 *
 * This is the single place controllers are constructed and asked to register
 * their routes. Controllers no longer register from their constructors, so
 * adding one here is the whole wiring step.
 */
class RestAPI {
    use SingletonTrait;

    protected function __construct() {
        add_action( 'rest_api_init', [ $this, 'loyalty_endpoints' ] );
    }

    /**
     * Controllers owning routes in this namespace.
     *
     * @return AbstractRestController[]
     */
    private function controllers(): array {
        return [
            new SettingsRestController(),
        ];
    }

    public function loyalty_endpoints() {
        foreach ( $this->controllers() as $controller ) {
            $controller->register_routes();
        }
    }
}
