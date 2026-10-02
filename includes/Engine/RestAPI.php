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
use YayLoyalty\Controllers\RedemptionsRestController;
use YayLoyalty\Controllers\ReferralsRestController;
use YayLoyalty\Controllers\RewardRestController;
use YayLoyalty\Controllers\SettingsRestController;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerMetaRepository;
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
        // An authorised LedgerService construction site; see its docblock.
        $customers = new CustomerRepository();
        $ledger    = new LedgerRepository();

        $ledger_service = new LedgerService( $ledger, $customers );

        // Read-only cross-table aggregates; reads the ledger, never writes it.
        $rewards   = new RewardRepository();
        $dashboard = new DashboardRepository( $rewards );
        $campaigns = new CampaignRepository();

        // Shared instance: written only by CustomerActivityService (Engine\CustomerActivityListener),
        // read-only everywhere below.
        $customer_meta = new CustomerMetaRepository();

        $controllers = [
            new SettingsRestController(),
            new DesignRestController(),
            new EmailsRestController(),
            // $ledger and $rewards here are read-only uses (counts.ledger,
            // the drawer's conversion rate) — the same instances LedgerService
            // and DashboardRepository hold, not a second construction.
            // $customer_meta backs deleted_at/loose_ends/counts.activity and
            // the list's identity fallback.
            new CustomerRestController( $customers, $ledger_service, $ledger, $rewards, $customer_meta ),
            // Same read-only $customers/$rewards instances as above, plus
            // $dashboard for its liability aggregate — not a second construction.
            new CustomerAdjustPreviewRestController( $customers, $dashboard, $rewards ),
            new LedgerRestController( $ledger ),
            new ActivityRestController( $customer_meta ),
            // Empty envelopes until their tables exist.
            new RedemptionsRestController(),
            new ReferralsRestController(),
            // Picker name lookups; no repository, it reads WooCommerce and WP core directly.
            new LookupRestController(),
            // Definition table: repository only, no service. $ledger is read-only here too.
            new CampaignRestController( $campaigns, $ledger ),
            // Same $rewards instance DashboardRepository holds.
            new RewardRestController( $rewards ),
            new DashboardRestController(
                new DashboardStats( $dashboard, wc_get_price_decimals() ),
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
