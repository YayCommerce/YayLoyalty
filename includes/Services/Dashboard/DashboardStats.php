<?php
namespace YayLoyalty\Services\Dashboard;

use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the cacheable part of GET /dashboard: stats, series, top earners,
 * recent activity. Previous liability is approximated as net_balance −
 * net_delta: the positive-only filter cannot be rewound without a snapshot.
 */
final class DashboardStats {

    public const TOP_EARNERS   = 3;
    public const ACTIVITY_ROWS = 6;

    /** @var DashboardRepository */
    private $repository;

    /** @var int The store currency's decimals, for the liability's money figure. */
    private $currency_decimals;

    public function __construct( DashboardRepository $repository, int $currency_decimals ) {
        $this->repository        = $repository;
        $this->currency_decimals = $currency_decimals;
    }

    /**
     * @return array{stats: array, series: array, top_earners: array, recent_activity: array}
     */
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
        $ppcu         = $repo->points_per_currency_unit();

        $orders_total       = $repo->count_orders( $window->from_ts(), $window->to_ts() );
        $orders_with_points = $repo->orders_with_points( $from, $to );
        $rate               = PointsMath::pct_of( $orders_with_points, $orders_total );
        $rate_prev          = PointsMath::pct_of(
            $repo->orders_with_points( $pf, $pt ),
            $repo->count_orders( $window->prev_from_ts(), $window->from_ts() )
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
                    'points'                   => $liability,
                    'currency_amount'          => PointsMath::points_to_currency( $liability, $ppcu, $this->currency_decimals ),
                    'points_per_currency_unit' => $ppcu,
                    'prev_delta_pct'           => PointsMath::pct_delta( $liability, $repo->net_balance() - $repo->net_delta( $from, $to ) ),
                ],
                'redemption_rate' => [
                    'rate'               => $rate,
                    'orders_with_points' => $orders_with_points,
                    'orders_total'       => $orders_total,
                    'prev_delta_pct'     => null === $rate || null === $rate_prev ? null : PointsMath::pct_delta( $rate, $rate_prev ),
                ],
            ],
            'series'          => DashboardMath::fill_series(
                $repo->daily_points( $from, $to, $window->utc_offset_seconds() ),
                $window->days(),
                [ 'issued', 'redeemed' ]
            ),
            'top_earners'     => $repo->top_earners( self::TOP_EARNERS ),
            'recent_activity' => [
                'total_today' => $repo->count_activity_since( $window->today_from_utc() ),
                'rows'        => $repo->latest_activity( self::ACTIVITY_ROWS ),
            ],
        ];
    }
}
