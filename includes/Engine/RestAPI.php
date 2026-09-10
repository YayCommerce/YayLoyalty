<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\CampaignRestController;
use YayLoyalty\Controllers\CustomerRestController;
use YayLoyalty\Controllers\LedgerRestController;
use YayLoyalty\Controllers\SettingsRestController;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Log;
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

        $controllers = [
            new SettingsRestController(),
            new CustomerRestController( $customers, $ledger_service ),
            new LedgerRestController( $ledger ),
            // Definition table: repository only, no service.
            new CampaignRestController( new CampaignRepository() ),
        ];

        /**
         * Controllers owning routes in the yayloyalty/v1 namespace.
         *
         * The attach point Pro uses to add its own endpoints, so the Lite
         * build needs no knowledge of them.
         *
         * Subscribers MUST return AbstractRestController instances — anything
         * else is skipped and logged by loyalty_endpoints() rather than fataling
         * the whole REST API.
         *
         * Internal seam for first-party Pro code, not a documented
         * third-party API.
         *
         * @param AbstractRestController[] $controllers
         */
        return apply_filters( 'yayloyalty_rest_controllers', $controllers );
    }

    public function loyalty_endpoints() {
        foreach ( $this->controllers() as $controller ) {
            // A filter subscriber returning the wrong type would otherwise
            // fatal on ->register_routes() and take down every YayLoyalty
            // endpoint, not just its own.
            if ( ! $controller instanceof AbstractRestController ) {
                // Warning, not debug: the subscriber's endpoints are silently
                // missing from the API, and nobody debugging that would think
                // to turn WP_DEBUG on first.
                Log::warning(
                    'RestAPI',
                    'ignoring a yayloyalty_rest_controllers entry that is not an AbstractRestController ('
                    . ( is_object( $controller ) ? get_class( $controller ) : gettype( $controller ) ) . ').'
                );

                continue;
            }

            $controller->register_routes();
        }
    }
}
