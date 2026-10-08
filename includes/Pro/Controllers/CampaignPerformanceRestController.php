<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\CampaignRestController;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\Repositories\CampaignPerformanceRepository;
use YayLoyalty\Pro\Repositories\CampaignPointsRepository;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\Dashboard\DashboardMath;
use YayLoyalty\Services\Dashboard\PeriodWindow;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Pro-only: one campaign's performance totals, daily chart and paged entries.
 *
 * Two time bases on purpose. `totals` is lifetime, so it agrees with the campaign list's `points_issued`.
 * `daily` and its `window` cover at most the last 30 days of the campaign's run, because a lifetime chart
 * would be unreadable. The chart prints `window` beside its title so the two are not confused.
 *
 * Not cached: DashboardCache's key has no campaign id, so every campaign would read the first one cached.
 * `skipped` and `estimate` are null, because they would need every order replayed through the earning engine.
 * `totals.avg_order_subtotal` is null, because nothing stores the base amount an award was scored on.
 */
final class CampaignPerformanceRestController extends AbstractRestController {

    /**
     * Triggers this page understands.
     *
     * @var string[]
     */
    public const TRIGGERS = [
        CampaignTrigger::PURCHASE,
        CampaignTrigger::SIGNUP,
        CampaignTrigger::REVIEW,
    ];

    /** @var DashboardRepository */
    private $dashboard;

    /** @var CampaignRepository */
    private $campaigns;

    /** @var CampaignPerformanceRepository */
    private $performance;

    /** @var CampaignPointsRepository */
    private $points;

    /** @var LedgerRepository */
    private $ledger;

    public function __construct(
        DashboardRepository $dashboard,
        CampaignRepository $campaigns,
        CampaignPerformanceRepository $performance,
        CampaignPointsRepository $points,
        LedgerRepository $ledger
    ) {
        $this->dashboard   = $dashboard;
        $this->campaigns   = $campaigns;
        $this->performance = $performance;
        $this->points      = $points;
        $this->ledger      = $ledger;
    }

    /** A sub-route of /campaigns, not a resource of its own. */
    public function get_rest_base(): string {
        return 'campaigns';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns/(?P<id>[\d]+)/performance',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_performance' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns/(?P<id>[\d]+)/performance/entries',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_entries' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => [
                        'page'     => [
                            'description'       => __( 'Current page of entries.', 'yayloyalty' ),
                            'type'              => 'integer',
                            'default'           => 1,
                            'minimum'           => 1,
                            'sanitize_callback' => 'absint',
                        ],
                        'per_page' => [
                            'description'       => __( 'Maximum number of entries to return.', 'yayloyalty' ),
                            'type'              => 'integer',
                            'default'           => 10,
                            'minimum'           => 1,
                            'maximum'           => 50,
                            'sanitize_callback' => 'absint',
                        ],
                        'search'   => [
                            'description'       => __( 'Filter by customer name or reference number.', 'yayloyalty' ),
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_performance( \WP_REST_Request $request ) {
        $campaign = $this->resolve_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        $row    = $campaign->to_array();
        $totals = $this->performance->totals( $row['id'] );
        $window = $this->chart_window( $row );

        return [
            'campaign_id' => $row['id'],
            // The same shape GET /campaigns/{id} answers, so the page's header needs no second request.
            'campaign'    => CampaignRestController::decorate( $this->ledger, [ $campaign ] )[0],
            'window'      => $window,
            'totals'      => $this->shape_totals( $row, $totals ),
            'daily'       => $this->daily_series( $row['id'], $window ),
            'skipped'     => null,
            'estimate'    => null,
        ];
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_entries( \WP_REST_Request $request ) {
        $campaign = $this->resolve_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        $result = $this->performance->paginate_entries(
            (int) $campaign->get_id(),
            (int) $request['page'],
            (int) $request['per_page'],
            (string) $request['search']
        );

        return $this->respond_paginated( $result );
    }

    /**
     * Null, so a plain 404, both for an unknown id and for a trigger this page does not support,
     * so a client cannot tell the two apart.
     */
    private function resolve_campaign( int $id ): ?Campaign {
        $campaign = $this->campaigns->find_campaign( $id );

        if ( null === $campaign || ! in_array( $campaign->get_trigger(), self::TRIGGERS, true ) ) {
            return null;
        }

        return $campaign;
    }

    /**
     * Adds what the repository cannot: `orders_total`, a sitewide order count that is not
     * scoped to this campaign.
     *
     * @param array<string, mixed> $row
     * @param array{points_issued: int, entries_total: int, orders_affected: int, customers_earned: int} $totals
     * @return array<string, mixed>
     */
    private function shape_totals( array $row, array $totals ): array {
        $shaped = [
            'points_issued'        => $totals['points_issued'],
            'entries_total'        => $totals['entries_total'],
            'orders_affected'      => null,
            'orders_total'         => null,
            'customers_earned'     => null,
            'avg_points_per_order' => null,
            'avg_order_subtotal'   => null,
        ];

        if ( CampaignTrigger::PURCHASE !== $row['trigger'] ) {
            $shaped['customers_earned'] = $totals['customers_earned'];

            return $shaped;
        }

        $shaped['orders_affected'] = $totals['orders_affected'];
        $shaped['orders_total']    = $this->dashboard->count_orders( 0, time() + 1 );

        if ( $totals['orders_affected'] > 0 ) {
            $shaped['avg_points_per_order'] = intdiv( $totals['points_issued'], $totals['orders_affected'] );
        }

        return $shaped;
    }

    /**
     * Up to 30 site-timezone days ending today, or on the campaign's last day if it has ended,
     * never starting before the campaign did.
     *
     * @param array<string, mixed> $row
     * @return array{from: string, to: string} Y-m-d, site-tz, both ends inclusive.
     */
    private function chart_window( array $row ): array {
        $now = Dates::site_day_start( Dates::now() );

        $starts = Dates::site_day_start( $row['starts_at'] );
        // ends_at is exclusive, so the last live day holds the second before it: a 10:00 end keeps that morning.
        $last_day = Dates::site_day_start( Dates::is_valid( $row['ends_at'] ) ? Dates::offset( -1, $row['ends_at'] ) : null );

        $to = null !== $last_day && $last_day < $now ? $last_day : $now;

        $from = $to->modify( '-29 days' );

        if ( null !== $starts && $starts > $from ) {
            $from = $starts;
        }

        // A campaign that starts after $to gets a one-day window, rather than $from > $to and zero days.
        if ( $from > $to ) {
            $to = $from;
        }

        return [
            'from' => $from->format( 'Y-m-d' ),
            'to'   => $to->format( 'Y-m-d' ),
        ];
    }

    /**
     * Points per site-timezone day of `$window`, zero-filled like the dashboard's daily series.
     *
     * @param array{from: string, to: string} $window
     * @return array<int, array{date: string, points: int}>
     */
    private function daily_series( int $campaign_id, array $window ): array {
        $from_utc = (string) Dates::site_day_to_utc( $window['from'] );

        $rows = $this->points->daily_by_campaign(
            $from_utc,
            (string) Dates::site_day_to_utc( $window['to'], '+1 day' ),
            wp_timezone()->getOffset( new \DateTimeImmutable( $window['from'], wp_timezone() ) ),
            [ $campaign_id ]
        );

        $by_date = array_map(
            static fn( array $row ): array => [
                'date'   => $row['date'],
                'points' => (int) $row['issued'],
            ],
            $rows
        );

        return DashboardMath::fill_series( $by_date, PeriodWindow::days_between( $window['from'], $window['to'] ), [ 'points' ] );
    }

    /**
     * No item schema: both routes return a report, not a resource.
     *
     * @return array<string, mixed>
     */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_campaign_performance',
            'type'       => 'object',
            'properties' => [],
        ];
    }
}
