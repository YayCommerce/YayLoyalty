<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\CustomerRestController;
use YayLoyalty\Controllers\LedgerRestController;
use YayLoyalty\Controllers\SettingsRestController;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
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
        // THE SINGLE CONSTRUCTION SITE for LedgerService, and the only place
        // both repositories are held together. See docs/code-standards.md
        // section 8: PHP has no package-private, so "only LedgerService writes
        // the ledger" is a convention plus this one construction site plus a
        // review rule. Do not hand both repositories to anything else.
        $customers = new CustomerRepository();
        $ledger    = new LedgerRepository();

        $ledger_service = new LedgerService( $ledger, $customers );

        return [
            new SettingsRestController(),
            new CustomerRestController( $customers, $ledger_service ),
            new LedgerRestController( $ledger ),
        ];
    }

    public function loyalty_endpoints() {
        foreach ( $this->controllers() as $controller ) {
            $controller->register_routes();
        }
    }
}
