<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Advanced "Max points per customer per day": the award-time cap
 * on what one customer earns automatically in a site-calendar day, across
 * every source. Subscribes to EarningService::AWARD_POINTS_FILTER, so it
 * lowers an award to what is left under the cap and never writes itself.
 *
 * Today's total is gross EARN (LedgerRepository::points_earned_today with no
 * source): manual adjustments are neither capped nor counted, and a refund
 * does not free up room. preview() is not capped, so pending points and
 * storefront figures stay the uncapped best case.
 *
 * The filter runs inside LedgerService::earn(), under the customer's row
 * lock, so two awards for one customer at the same moment queue and the
 * second sees the first's row.
 */
final class DailyEarningLimit {

    private LedgerRepository $ledger;

    public function __construct( LedgerRepository $ledger ) {
        $this->ledger = $ledger;
    }

    /**
     * The `yayloyalty_award_points` subscriber: $points lowered to the cap
     * minus today's EARN total, never below 0. Unchanged when the cap is 0
     * (no limit), there is no customer, or there is nothing to award.
     *
     * @param int|string $points The evaluated points, as an earlier subscriber left them; non-numeric counts as 0, as in award().
     */
    public function clamp( $points, string $trigger, Context $ctx ): int {
        $points  = is_numeric( $points ) ? (int) $points : 0;
        $cap     = (int) SettingsHelper::get_settings()['advanced']['max_points_per_day'];
        $user_id = $ctx->get_user_id();

        if ( $cap < 1 || null === $user_id || $points <= 0 ) {
            return $points;
        }

        $earned  = $this->ledger->points_earned_today( $user_id, Dates::now(), null );
        $allowed = max( 0, min( $points, $cap - $earned ) );

        if ( $allowed < $points ) {
            Log::warning(
                'DailyEarningLimit',
                sprintf( 'user #%d %s: evaluated %d, awarded %d, cap %d, earned today %d', $user_id, $trigger, $points, $allowed, $cap, $earned )
            );
        }

        return $allowed;
    }
}
