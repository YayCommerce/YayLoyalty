<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Services\ExpiryRules;

defined( 'ABSPATH' ) || exit;

/**
 * Re-dates every lot and customer after the expiry settings change, by the rules Services\ExpiryRules
 * applies row by row: a shorter window, a longer one, a switch to batches, or expiry turned off reaches
 * every customer at once, not only those who earn next.
 */
final class ExpiryRecompute {

    /** @throws \YayLoyalty\Database\QueryException When the update fails. */
    public static function run(): void {
        $lots      = new LotRepository();
        $customers = new CustomerRepository();

        if ( ExpiryRules::enabled() && ExpiryRules::MODEL_BATCHES === ExpiryRules::model() ) {
            // Lots first: the customers' dates are read from them.
            $lots->recompute_expiry( ExpiryRules::batch_months() );
            $customers->recompute_expiry_from_lots();

            return;
        }

        $lots->recompute_expiry( null );
        $customers->recompute_inactivity_expiry( ExpiryRules::enabled() ? ExpiryRules::inactivity_days() : null );
    }
}
