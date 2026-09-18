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

/** The single place controllers are constructed and register their routes. */
class RestAPI {
    use SingletonTrait;

    protected function __construct() {
        add_action( 'rest_api_init', [ $this, 'loyalty_endpoints' ] );
        add_action( 'yayloyalty_ledger_recorded', [ DashboardCache::class, 'flush' ] );
        add_action( 'yayloyalty_campaigns_changed', [ DashboardCache::class, 'flush' ] );
    }

    /**
     * Controllers owning routes in this namespace.
     *
     * @return AbstractRestController[]
     */
    private function controllers(): array {
        // One of LedgerService's two authorised construction sites; see its docblock.
        $customers = new CustomerRepository();
        $ledger    = new LedgerRepository();

        $ledger_service = new LedgerService( $ledger, $customers );

        $dashboard = new DashboardRepository( new RewardRepository() );
        $campaigns = new CampaignRepository();

        $controllers = [
            new SettingsRestController(),
            new CustomerRestController( $customers, $ledger_service ),
            new LedgerRestController( $ledger ),
            new CampaignRestController( $campaigns ),
            new DashboardRestController(
                new DashboardStats( $dashboard ),
                new DashboardCache( $dashboard ),
                new DashboardNotices( $dashboard, $campaigns ),
                new NoticeDismissals()
            ),
        ];

        /**
         * Controllers owning routes in yayloyalty/v1. Internal Pro seam; anything not an AbstractRestController is skipped and logged.
         *
         * @param AbstractRestController[] $controllers
         */
        return apply_filters( 'yayloyalty_rest_controllers', $controllers );
    }

    public function loyalty_endpoints() {
        foreach ( $this->controllers() as $controller ) {
            // A wrong type would fatal on register_routes() and take down every endpoint.
            if ( ! $controller instanceof AbstractRestController ) {
                // Warning, not debug: nobody debugging missing endpoints turns WP_DEBUG on first.
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
