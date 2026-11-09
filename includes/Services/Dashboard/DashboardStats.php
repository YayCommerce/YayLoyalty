<?php
namespace YayLoyalty\Services\Dashboard;

use YayLoyalty\Repositories\DashboardRepository;
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

    public function __construct( DashboardRepository $repository ) {
        $this->repository = $repository;
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

        $orders_total           = $repo->count_paid_orders( $window->from_ts(), $window->to_ts() );
        $orders_with_redemption = $repo->paid_orders_with_redemption( $window->from_ts(), $window->to_ts() );
        $rate                   = PointsMath::pct_of( $orders_with_redemption, $orders_total );
        $rate_prev              = PointsMath::pct_of(
            $repo->paid_orders_with_redemption( $window->prev_from_ts(), $window->from_ts() ),
            $repo->count_paid_orders( $window->prev_from_ts(), $window->from_ts() )
        );

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
}
