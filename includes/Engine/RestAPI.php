<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\CampaignRestController;
use YayLoyalty\Controllers\CustomerRestController;
use YayLoyalty\Controllers\DashboardRestController;
use YayLoyalty\Controllers\LedgerRestController;
use YayLoyalty\Controllers\SettingsRestController;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\Dashboard\DashboardCache;
use YayLoyalty\Services\Dashboard\DashboardNotices;
use YayLoyalty\Services\Dashboard\DashboardStats;
use YayLoyalty\Services\Dashboard\NoticeDismissals;
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
        // Every ledger or campaign write invalidates the (large-store-only) dashboard cache.
        add_action( 'yayloyalty_ledger_recorded', [ DashboardCache::class, 'flush' ] );
        add_action( 'yayloyalty_campaigns_changed', [ DashboardCache::class, 'flush' ] );
    }

    /**
     * Controllers owning routes in this namespace.
     *
     * @return AbstractRestController[]
     */
    private function controllers(): array {
        // ONE OF TWO AUTHORISED CONSTRUCTION SITES for LedgerService — the
        // other is Database\InitYayLoyaltyCustomer::maybe_create() (install-
        // time bootstrap, see its docblock). See docs/code-standards.md
        // section 8: PHP has no package-private, so "only LedgerService writes
        // the ledger" is a convention plus these two named sites plus a
        // review rule. Do not hand both repositories to a third place.
        $customers = new CustomerRepository();
        $ledger    = new LedgerRepository();

        $ledger_service = new LedgerService( $ledger, $customers );

        // Read-only cross-table aggregates; reads the ledger, never writes it.
        $rewards   = new RewardRepository();
        $dashboard = new DashboardRepository( $rewards );
        $campaigns = new CampaignRepository();

        $controllers = [
            new SettingsRestController(),
            // $ledger and $rewards here are read-only uses (counts.ledger,
            // worth's conversion rate) — the same instances LedgerService
            // and DashboardRepository hold, not a second construction.
            new CustomerRestController( $customers, $ledger_service, $ledger, $rewards ),
            new LedgerRestController( $ledger ),
            // Definition table: repository only, no service.
            new CampaignRestController( $campaigns ),
            new DashboardRestController(
                new DashboardStats( $dashboard ),
                new DashboardCache( $dashboard ),
                new DashboardNotices( $dashboard, $campaigns ),
                new NoticeDismissals()
            ),
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
