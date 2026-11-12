<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Enums\TierMode;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\Tier;
use YayLoyalty\Pro\Services\TierCalendar;
use YayLoyalty\Pro\Services\TierEvaluator;
use YayLoyalty\Pro\Services\TierStanding;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The launcher's tier line: the member's tier, progress to the next one and, in Rolling or Calendar, the window or
 * period and the hold.
 * It reads TierStanding, as the drawer's tier card does, so the two show the same standing.
 */
final class TierProgress {

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( LauncherPayload::MEMBER_TIER_FILTER, [ $this, 'member_tier' ], 10, 3 );
    }

    /**
     * Null when tiers are off or no tier is live. For the Design preview ($user_id null), a sample: the lowest
     * live tier, halfway to the next one. $balance is ignored: the preview's sample balance (9,850) sits past
     * the top tier on the seed tiers and would never show a typical line.
     *
     * @param array|null $value   The filter's value so far.
     * @param int|null   $user_id The member; null for the Design preview.
     * @param int        $balance The member's balance, or the preview's sample.
     * @return array{name: ?string, color: ?string, next: ?array{name: string, threshold: int}, progress: int, needed: int, window_months: ?int, period: ?string, held_until: ?string}|null
     */
    public function member_tier( $value, ?int $user_id, int $balance ): ?array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter passes $value and $balance.
        if ( ! TierEvaluator::is_enabled() ) {
            return null;
        }

        $live = ( new TierRepository() )->live();

        if ( [] === $live ) {
            return null;
        }

        if ( null === $user_id ) {
            return self::sample( $live );
        }

        $customer = ( new CustomerRepository() )->find_by_user_id( $user_id ) ?? new Customer( $user_id );
        $standing = ( new TierStanding( new LedgerRepository() ) )->of( $customer, $live, Dates::now() );
        $tier     = $standing['tier'];

        return self::line( $tier, $standing['next'], $standing['progress'], $standing['window_months'], $standing['period'], null === $tier ? null : $standing['held_until'] );
    }

    /**
     * The preview's line: the lowest live tier, progress at the midpoint of its threshold and the next one's
     * (its own threshold when it is the only tier) and, in Rolling, a hold that ends one window from now; in Calendar,
     * the hold a tier reached today would get.
     *
     * @param Tier[] $live TierRepository::live(), in rank order; not empty.
     * @return array{name: ?string, color: ?string, next: ?array{name: string, threshold: int}, progress: int, needed: int, window_months: ?int, period: ?string, held_until: ?string}
     */
    private static function sample( array $live ): array {
        $tier     = $live[0];
        $next     = $live[1] ?? null;
        $progress = null === $next ? $tier->get_threshold() : intdiv( $tier->get_threshold() + $next->get_threshold(), 2 );
        $mode     = TierEvaluator::mode();
        $months   = TierMode::ROLLING === $mode ? TierEvaluator::rolling_months() : null;
        $period   = TierMode::CALENDAR === $mode ? TierEvaluator::calendar_period() : null;

        if ( null !== $period ) {
            $tz         = wp_timezone();
            $held_until = TierCalendar::hold_dates( TierCalendar::SOURCE_CURRENT, TierCalendar::bounds( Dates::now(), $period, $tz ), $period, $tz )['until'];
        } else {
            $held_until = null === $months ? null : Dates::add_months( Dates::now(), $months );
        }

        return self::line( $tier, $next, $progress, $months, $period, $held_until );
    }

    /**
     * The line's fields from a standing. AccountTier builds My Points' tier section on it, so the two agree.
     *
     * @return array{name: ?string, color: ?string, next: ?array{name: string, threshold: int}, progress: int, needed: int, window_months: ?int, period: ?string, held_until: ?string}
     */
    public static function line( ?Tier $tier, ?Tier $next, int $progress, ?int $window_months, ?string $period, ?string $held_until ): array {
        return [
            'name'          => null === $tier ? null : $tier->get_name(),
            'color'         => null === $tier ? null : $tier->get_color(),
            'next'          => null === $next ? null : [
                'name'      => $next->get_name(),
                'threshold' => $next->get_threshold(),
            ],
            'progress'      => $progress,
            'needed'        => null === $next ? 0 : max( 0, $next->get_threshold() - $progress ),
            'window_months' => $window_months,
            'period'        => $period,
            'held_until'    => $held_until,
        ];
    }
}
