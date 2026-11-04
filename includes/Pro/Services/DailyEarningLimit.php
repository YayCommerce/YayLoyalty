<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → General "Fraud limits", the daily limit per customer: the award-time cap
 * on what one customer earns automatically in a site-calendar day, across
 * every source. Subscribes to EarningService::AWARD_POINTS_FILTER, so it
 * lowers an award to what is left under the cap and never writes itself.
 *
 * Today's total is gross automatic EARN (LedgerRepository::points_earned_on_site_day with no
 * source): manual adjustments and admin grants for a missed order are neither capped nor counted, and a refund
 * does not free up room. preview() is not capped, so pending points stay
 * the uncapped best case; the storefront reads left_today() instead.
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
     * minus today's EARN total, never below 0. Unchanged when the cap is null
     * (no limit), there is no customer, or there is nothing to award.
     *
     * @param int|string $points The evaluated points, as an earlier subscriber left them; non-numeric counts as 0, as in award().
     */
    public function clamp( $points, string $trigger, Context $ctx ): int {
        $points  = is_numeric( $points ) ? (int) $points : 0;
        $user_id = $ctx->get_user_id();
        $left    = null === $user_id || $points <= 0 ? null : $this->left_today( null, $user_id );

        if ( null === $left ) {
            return $points;
        }

        $allowed = min( $points, $left );

        if ( $allowed < $points ) {
            Log::warning(
                'DailyEarningLimit',
                sprintf( 'user #%d %s: evaluated %d, awarded %d, left today %d', $user_id, $trigger, $points, $allowed, $left )
            );
        }

        return $allowed;
    }

    /**
     * The `yayloyalty_points_left_today` subscriber: the cap minus today's EARN total, never below 0, or null with no cap.
     *
     * @param int|null $left An earlier subscriber's answer; the cap replaces it.
     */
    public function left_today( $left, int $user_id ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter's signature.
        $cap = SettingsHelper::get_settings()['max_points_per_day'];

        if ( null === $cap ) {
            return null;
        }

        return max( 0, $cap - $this->ledger->points_earned_on_site_day( $user_id, Dates::now(), null ) );
    }
}
