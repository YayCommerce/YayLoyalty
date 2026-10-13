<?php
namespace YayLoyalty;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Listeners\OrderListener;
use YayLoyalty\Engine\Earning\Listeners\OrderReversalListener;
use YayLoyalty\Engine\Earning\Listeners\ReviewListener;
use YayLoyalty\Engine\Earning\Listeners\SignupListener;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\SingletonTrait;

defined( 'ABSPATH' ) || exit;

/** Plugin entry point: boots Pro when present, then every component. */
class YayLoyalty {

    use SingletonTrait;

    protected function __construct() {
        \YayLoyalty\Engine\RestAPI::get_instance();
        \YayLoyalty\Engine\Register\RegisterFacade::get_instance();
        \YayLoyalty\Engine\Admin\Settings::get_instance();
        \YayLoyalty\Engine\Emails\Emails::get_instance();
        \YayLoyalty\Integrations\YayMail\YayMailIntegration::get_instance();

        // The only reference to Pro in the Lite tree. Lite strips includes/Pro/, so add a seam, never a second reference.
        // It runs before rest_api_init and any campaign read, so Pro's filters are attached first.
        if ( self::is_pro() ) {
            \YayLoyalty\Pro\YayLoyaltyPro::boot();
        }

        // After Pro's boot: build() applies yayloyalty_earning_gates once, so any gate Pro adds must already be attached.
        $this->register_earning_listeners();

        \YayLoyalty\Engine\Expiry\ExpiryJob::build()->register();

        \YayLoyalty\Engine\Storefront\AccountRewards::register();
        \YayLoyalty\Engine\Storefront\Placements::register();
        \YayLoyalty\Engine\Storefront\ProductEarnMessage::register();
        \YayLoyalty\Engine\Storefront\ThankYouPoints::register();

        if ( ! is_admin() ) {
            \YayLoyalty\Engine\Storefront\Launcher::register();
        }
    }

    public static function initialize() {
        self::get_instance();
    }

    /** Whether this zip was built as Pro. Not a licence check, and never localised: the SPA is gated at build time. */
    public static function is_pro(): bool {
        return class_exists( 'YayLoyalty\Pro\YayLoyaltyPro', true );
    }

    /** Builds the earning composition root and attaches its four listeners. Runs once, at boot. */
    private function register_earning_listeners(): void {
        $service = EarningService::build();

        $ledger    = new LedgerRepository();
        $pending   = new PendingPoints( new CustomerRepository() );
        $reversal  = Reversal::build();
        $reversals = new OrderReversalListener( $pending, $reversal );

        ( new OrderListener( $service, $pending, $ledger, $reversals ) )->register();
        $reversals->register();
        ( new ReviewListener( $service, $reversal ) )->register();
        ( new SignupListener( $service ) )->register();
    }
}
