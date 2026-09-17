<?php
namespace YayLoyalty\Services\Dashboard;

use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's three real notice detectors, as structured data only:
 * `{ key, severity, fingerprint, data }`. No title, body or action text —
 * the admin app owns every word (same rule as ledger descriptions), so
 * nothing here calls __().
 *
 * Runs inside the site-wide cached payload; per-user dismissal is applied
 * afterwards by NoticeDismissals. The fingerprint is what a dismissal is
 * keyed on, so it changes exactly when the notice should re-surface.
 *
 * Not final: WordPress is reached only through the two protected methods at
 * the bottom, which the unit test overrides.
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

        // Index-tagged so equal severities keep detector order on PHP 7.4,
        // where usort() is not stable.
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
        $now_utc = $now_site->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Dates::MYSQL_FORMAT );
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

    /**
     * Always evaluated over its own 30-day series, independent of the chart
     * range, so the notice does not flicker as the admin switches 7/30/90.
     */
    private function points_jump( \DateTimeImmutable $now_site ): ?array {
        $window = PeriodWindow::for_range( 30, $now_site );
        $days   = $window->days();
        $series = DashboardMath::fill_series(
            $this->repository->daily_points( $window->from_utc(), $window->to_utc(), $window->utc_offset_seconds() ),
            $days,
            [ 'issued' ]
        );

        $yesterday     = $days[ count( $days ) - 2 ];
        $day_start     = new \DateTimeImmutable( $yesterday, $now_site->getTimezone() );
        $day_end       = $day_start->modify( '+1 day' );
        $day_start_utc = $day_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Dates::MYSQL_FORMAT );
        $day_end_utc   = $day_end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( Dates::MYSQL_FORMAT );

        $result = $this->detector->evaluate(
            array_column( $series, 'issued', 'date' ),
            $this->repository->count_orders( $day_start->getTimestamp(), $day_end->getTimestamp() ),
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

    /** Was the campaign's last write on $day, in site time? */
    private function edited_on( Campaign $campaign, string $day, \DateTimeZone $tz ): bool {
        $updated = date_create_immutable( $campaign->get_updated_at(), new \DateTimeZone( 'UTC' ) );

        return false !== $updated && $updated->setTimezone( $tz )->format( 'Y-m-d' ) === $day;
    }

    protected function user_display_name( int $user_id ): string {
        $user = get_userdata( $user_id );

        return $user instanceof \WP_User ? $user->display_name : '';
    }

    /**
     * WooCommerce Orders list filtered to a month (`YYYYMM`) — WC's list
     * filters by month only; the SPA names the day in the link text.
     */
    protected function orders_admin_url( string $month ): string {
        $hpos = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        return admin_url( ( $hpos ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order' ) . '&m=' . $month );
    }
}
