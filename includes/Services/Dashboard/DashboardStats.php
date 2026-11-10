<?php
namespace YayLoyalty\Services\Dashboard;

use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Repositories\RedemptionRateRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the cacheable part of GET /dashboard: stats, series, top balances,
 * recent activity. Previous liability is approximated as net_balance −
 * net_delta: the positive-only filter cannot be rewound without a snapshot.
 */
final class DashboardStats {

    public const TOP_BALANCES  = 3;
    public const ACTIVITY_ROWS = 6;

    /** @var DashboardRepository */
    private $repository;

    /** @var RedemptionRateRepository */
    private $redemption_rate;

    /** @var RewardRepository */
    private $rewards;

    public function __construct( DashboardRepository $repository, RedemptionRateRepository $redemption_rate, RewardRepository $rewards ) {
        $this->repository      = $repository;
        $this->redemption_rate = $redemption_rate;
        $this->rewards         = $rewards;
    }

    /** @return array{stats: array, series: array, top_balances: array, recent_activity: array} */
    public function build( PeriodWindow $window ): array {
        $repo = $this->repository;
        $from = $window->from_utc();
        $to   = $window->to_utc();
        $pf   = $window->prev_from_utc();
        $pt   = $window->prev_to_utc();

        $members      = $repo->count_members( $to );
        $members_prev = $repo->count_members( $pt );
        $issued       = $repo->sum_issued( $from, $to );
        $redeemed     = $repo->sum_redeemed( $from, $to );
        $liability    = $repo->liability_points();

        $threshold              = $this->redeemable_from();
        $orders                 = $this->redemption_rate->counts( $window->from_ts(), $window->to_ts(), $threshold );
        $orders_prev            = $this->redemption_rate->counts( $window->prev_from_ts(), $window->from_ts(), $threshold );
        $orders_total           = $orders['eligible'];
        $orders_with_redemption = $orders['redeemed'];
        $rate                   = PointsMath::pct_of( $orders_with_redemption, $orders_total );
        $rate_prev              = PointsMath::pct_of( $orders_prev['redeemed'], $orders_prev['eligible'] );

        return [
            'stats'           => [
                'members'         => [
                    'value'          => $members,
                    'prev_delta_pct' => PointsMath::pct_delta( $members, $members_prev ),
                ],
                'points_issued'   => [
                    'value'          => $issued,
                    'prev_delta_pct' => PointsMath::pct_delta( $issued, $repo->sum_issued( $pf, $pt ) ),
                ],
                'points_redeemed' => [
                    'value'          => $redeemed,
                    'prev_delta_pct' => PointsMath::pct_delta( $redeemed, $repo->sum_redeemed( $pf, $pt ) ),
                ],
                'liability'       => [
                    'points'         => $liability,
                    'prev_delta_pct' => PointsMath::pct_delta( $liability, $repo->net_balance() - $repo->net_delta( $from, $to ) ),
                ],
                'redemption_rate' => [
                    'rate'                   => $rate,
                    'orders_with_redemption' => $orders_with_redemption,
                    'orders_total'           => $orders_total,
                    'prev_delta_pct'         => null === $rate || null === $rate_prev ? null : PointsMath::pct_delta( $rate, $rate_prev ),
                ],
            ],
            'series'          => DashboardMath::fill_series(
                $repo->daily_points( $from, $to, $window->utc_offset_seconds() ),
                $window->days(),
                [ 'issued', 'redeemed' ]
            ),
            'top_balances'    => $repo->top_balances( self::TOP_BALANCES ),
            'recent_activity' => [
                'total_today' => $repo->count_activity_since( $window->today_from_utc() ),
                'rows'        => $repo->latest_activity( self::ACTIVITY_ROWS ),
            ],
        ];
    }

    /**
     * The fewest points a member needs to redeem any reward live now: the points discount's minimum on its
     * step, or a fixed reward's cost. Null when no reward is live. Today's rewards judge both windows, as
     * reward history is not kept; reward conditions are ignored.
     */
    private function redeemable_from(): ?int {
        $lowest = null;

        foreach ( $this->rewards->live_of_types( RewardType::all(), Dates::now() ) as $reward ) {
            if ( RewardType::FLEXIBLE_DISCOUNT === $reward->get_type() ) {
                $step = Clamp::step_of( $reward );
                $cost = (int) ( ceil( max( 1, Clamp::min_of( $reward ) ) / $step ) * $step );
            } else {
                $cost = (int) $reward->get_cost_points();
            }

            if ( $cost > 0 && ( null === $lowest || $cost < $lowest ) ) {
                $lowest = $cost;
            }
        }

        return $lowest;
    }
}
