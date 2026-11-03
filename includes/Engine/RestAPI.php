<?php
namespace YayLoyalty\Engine;

use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\ActivityRestController;
use YayLoyalty\Controllers\CampaignRestController;
use YayLoyalty\Controllers\CustomerAdjustPreviewRestController;
use YayLoyalty\Controllers\CustomerRestController;
use YayLoyalty\Controllers\DashboardRestController;
use YayLoyalty\Controllers\DesignRestController;
use YayLoyalty\Controllers\EmailsRestController;
use YayLoyalty\Controllers\LedgerRestController;
use YayLoyalty\Controllers\LookupRestController;
use YayLoyalty\Controllers\OrderPointsRestController;
use YayLoyalty\Controllers\RedemptionsRestController;
use YayLoyalty\Controllers\RewardRestController;
use YayLoyalty\Controllers\SettingsRestController;
use YayLoyalty\Controllers\SetupRestController;
use YayLoyalty\Controllers\StorefrontActivityRestController;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\OrderAward;
use YayLoyalty\Engine\Earning\OrderPointsRetry;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Services\Dashboard\DashboardCache;
use YayLoyalty\Services\Dashboard\DashboardNotices;
use YayLoyalty\Services\Dashboard\DashboardStats;
use YayLoyalty\Services\Dashboard\NoticeDismissals;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Services\SetupService;
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
        // A listed LedgerService construction site.
        $customers = new CustomerRepository();
        $ledger    = new LedgerRepository();

        $ledger_service = new LedgerService( $ledger, $customers, new CampaignRepository() );

        // Read-only cross-table aggregates: they read the ledger and never write it.
        $rewards   = new RewardRepository();
        $dashboard = new DashboardRepository();
        $campaigns = new CampaignRepository();

        // Read-only everywhere below. Only CustomerActivityService writes it.
        $customer_meta = new CustomerMetaRepository();

        // Read-only everywhere below. Only Engine\Redemption\CommitWriter inserts.
        $redemptions = new RedemptionRepository();

        $pending = new PendingPoints( $customers );

        $controllers = [
            new SettingsRestController(),
            new DesignRestController(),
            // Writes the built-in campaigns and the cart discount in place, through these repositories.
            new SetupRestController( new SetupService( $campaigns, $rewards ) ),
            new EmailsRestController(),
            // $ledger and $redemptions are read-only here.
            new CustomerRestController( $customers, $ledger_service, $ledger, $customer_meta, $redemptions ),
            // The retry writes through the award's own LedgerService (EarningService::build()); $ledger is read-only here.
            new OrderPointsRestController( new OrderPointsRetry( new OrderAward( EarningService::build(), $pending, $ledger ), $pending ), $ledger ),
            // $customers is read-only here too.
            new CustomerAdjustPreviewRestController( $customers, $dashboard ),
            new LedgerRestController( $ledger ),
            new ActivityRestController( $customer_meta ),
            // Shopper route (storefront launcher): session identity, no capability. $ledger is read-only here.
            new StorefrontActivityRestController( $ledger ),
            new RedemptionsRestController( $redemptions, $rewards ),
            // Picker name lookups; no repository, it reads WooCommerce and WP core directly.
            new LookupRestController(),
            // Definition table: repository only, no service. $ledger is read-only here too.
            new CampaignRestController( $campaigns, $ledger ),
            // Same $rewards instance DashboardRepository holds; redemptions read-only, for is_deletable.
            new RewardRestController( $rewards, $redemptions ),
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
