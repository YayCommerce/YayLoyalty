<?php
namespace YayLoyalty\Services\Dashboard;

use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\WooOrders;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's notice detectors, as structured data only: the SPA owns
 * every word. Runs inside the cached payload; dismissal is applied after,
 * keyed on the fingerprint, which changes exactly when a notice should
 * re-surface. Not final: a test overrides the two WordPress-touching methods.
 */
class DashboardNotices {

    private const RANK = [
        'affected' => 0,
        'waiting'  => 1,
        'progress' => 2,
    ];

    /** Bars the anomaly panel draws. */
    private const JUMP_DAYS = 13;

    /** @var DashboardRepository */
    private $repository;

    /** @var CampaignRepository */
    private $campaigns;

    /** @var AnomalyDetector */
    private $detector;

    public function __construct( DashboardRepository $repository, CampaignRepository $campaigns, ?AnomalyDetector $detector = null ) {
        $this->repository = $repository;
        $this->campaigns  = $campaigns;
        $this->detector   = $detector ?? new AnomalyDetector();
    }

    /**
     * @param \DateTimeImmutable $now_site "Now" in the site timezone.
     * @return array<int, array{key: string, severity: string, fingerprint: string, data: array}>
     */
    public function collect( \DateTimeImmutable $now_site ): array {
        $notices = array_values(
            array_filter(
                [
                    $this->negative_balance(),
                    $this->default_campaign_paused( $now_site ),
                    $this->points_jump( $now_site ),
                ]
            )
        );

        // Index-tagged: usort() is not stable on PHP 7.4.
        $tagged = array_map( static fn( int $i, array $n ): array => [ self::RANK[ $n['severity'] ], $i, $n ], array_keys( $notices ), $notices );
        usort( $tagged, static fn( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );

        return array_column( $tagged, 2 );
    }

    private function negative_balance(): ?array {
        $count = $this->repository->count_negative_balances();

        if ( $count <= 0 ) {
            return null;
        }

        if ( 1 === $count ) {
            $bucket = '1';
        } elseif ( $count < 10 ) {
            $bucket = '2-9';
        } elseif ( $count < 100 ) {
            $bucket = '10-99';
        } else {
            $bucket = '100+';
        }

        return [
            'key'         => 'negative_balance',
            'severity'    => 'waiting',
            'fingerprint' => 'count:' . $bucket,
            'data'        => [ 'count' => $count ],
        ];
    }

    private function default_campaign_paused( \DateTimeImmutable $now_site ): ?array {
        $campaign = $this->campaigns->find_default_purchase();

        if ( null === $campaign || Status::PAUSED !== $campaign->get_status() ) {
            return null;
        }

        $since   = $campaign->get_updated_at();
        $now_utc = Dates::to_utc( $now_site );
        $orders  = $this->repository->count_orders( Dates::to_timestamp( $since ), $now_site->getTimestamp() );

        return [
            'key'         => 'default_campaign_paused',
            'severity'    => 'affected',
            'fingerprint' => $since,
            'data'        => [
                'campaign_id'           => (int) $campaign->get_id(),
                'paused_since'          => $since,
                'orders_without_points' => max( 0, $orders - $this->repository->orders_with_earn( $since, $now_utc ) ),
            ],
        ];
    }

    /** Over its own 30-day series, independent of the chart range, so it does not flicker on range switch. */
    private function points_jump( \DateTimeImmutable $now_site ): ?array {
        $window = PeriodWindow::for_range( 30, $now_site );
        $days   = $window->days();
        $series = DashboardMath::fill_series(
            $this->repository->daily_points( $window->from_utc(), $window->to_utc(), $window->utc_offset_seconds() ),
            $days,
            [ 'issued' ]
        );

        $yesterday     = $days[ count( $days ) - 2 ];
        $day_start_utc = (string) Dates::site_day_to_utc( $yesterday );
        $day_end_utc   = (string) Dates::site_day_to_utc( $yesterday, '+1 day' );

        $result = $this->detector->evaluate(
            array_column( $series, 'issued', 'date' ),
            $this->repository->count_orders( Dates::to_timestamp( $day_start_utc ), Dates::to_timestamp( $day_end_utc ) ),
            $yesterday
        );

        if ( null === $result ) {
            return null;
        }

        $what_changed = [];

        foreach ( $this->campaigns->all_non_archived() as $campaign ) {
            if ( $this->edited_on( $campaign, $yesterday, $now_site->getTimezone() ) ) {
                $totals         = $this->repository->campaign_points_on_day( (int) $campaign->get_id(), $day_start_utc, $day_end_utc );
                $what_changed[] = [
                    'campaign_id'     => (int) $campaign->get_id(),
                    'name'            => $campaign->get_name(),
                    'updated_at'      => $campaign->get_updated_at(),
                    'updated_by_name' => null === $campaign->get_updated_by() ? '' : $this->user_display_name( $campaign->get_updated_by() ),
                    'orders_affected' => $totals['orders'],
                    'points_issued'   => $totals['points'],
                ];
            }
        }

        return [
            'key'         => 'points_jump',
            'severity'    => 'affected',
            'fingerprint' => $yesterday,
            'data'        => $result + [
                'yesterday'        => $yesterday,
                'daily'            => array_slice( array_slice( $series, 0, -1 ), -self::JUMP_DAYS ),
                'threshold'        => [
                    'multiple'   => AnomalyDetector::MULTIPLE,
                    'min_orders' => AnomalyDetector::MIN_ORDERS,
                ],
                'orders_admin_url' => $this->orders_admin_url( substr( str_replace( '-', '', $yesterday ), 0, 6 ) ),
                'what_changed'     => $what_changed,
            ],
        ];
    }

    /** Whether the campaign's last write was on $day, in site time. */
    private function edited_on( Campaign $campaign, string $day, \DateTimeZone $tz ): bool {
        $updated = date_create_immutable( $campaign->get_updated_at(), new \DateTimeZone( 'UTC' ) );

        return false !== $updated && $updated->setTimezone( $tz )->format( 'Y-m-d' ) === $day;
    }

    protected function user_display_name( int $user_id ): string {
        $user = get_userdata( $user_id );

        return $user instanceof \WP_User ? $user->display_name : '';
    }

    /** The Orders list filtered to a month (YYYYMM); WC filters by month only. */
    protected function orders_admin_url( string $month ): string {
        return admin_url( ( WooOrders::is_hpos() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order' ) . '&m=' . $month );
    }
}
