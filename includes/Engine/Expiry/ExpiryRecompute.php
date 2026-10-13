<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\ExpiryRules;

defined( 'ABSPATH' ) || exit;

/**
 * Re-dates every customer after the expiry settings change, by the rules Services\ExpiryRules applies row
 * by row: a shorter window, a longer one, or expiry turned off reaches every customer at once, not only
 * those who earn next.
 */
final class ExpiryRecompute {

    /** @throws \YayLoyalty\Database\QueryException When the update fails. */
    public static function run(): void {
        ( new CustomerRepository() )->recompute_inactivity_expiry( ExpiryRules::enabled() ? ExpiryRules::inactivity_days() : null );
    }
}
