<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\CampaignRestController;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Pro\Repositories\CampaignPerformanceRepository;
use YayLoyalty\Pro\Repositories\CampaignPointsRepository;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\Dashboard\DashboardMath;
use YayLoyalty\Services\Dashboard\PeriodWindow;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * Pro-only: a single campaign's performance —
 * `GET /campaigns/{id}/performance` (totals + daily chart) and
 * `GET /campaigns/{id}/performance/entries` (the paged, searchable row list
 * behind it: orders / signups / reviews depending on trigger). Real data —
 * CampaignPerformanceFixture is gone; every figure below now comes from
 * `ledger` (via CampaignPerformanceRepository / CampaignPointsRepository),
 * `wp_users` and WooCommerce.
 *
 * Supports `purchase`, `signup` and `review` only (self::TRIGGERS) — the
 * other triggers have no page for this build; resolve_row() 404s for them
 * exactly as it does for an unknown id, so a merchant clicking a link this
 * build never renders and one typing a stray URL see the same thing.
 *
 * Registered through `yayloyalty_rest_controllers`, so Lite has no route
 * here at all (404, not 403) — same as CampaignPreviewRestController.
 *
 * TWO TIME BASES ON PURPOSE. `totals` is lifetime — no date predicate —
 * so it always agrees with CampaignRestController's own `points_issued`
 * column (LedgerRepository::totals_by_campaign(), also lifetime). `daily`
 * (and the `window` that describes it) is the last 30 days, clamped to the
 * campaign's own run — see chart_window() — because a chart spanning a
 * campaign's entire life would be unreadable. The chart's own card prints
 * `window` next to its title (CampaignPerformanceChart.tsx) specifically so
 * this narrower span is never mistaken for the tiles above it.
 *
 * NOT CACHED. Do not wrap this in DashboardCache the way
 * DashboardProRestController does — that cache's key
 * (DashboardCache::key(), `prefix + variant + range`) carries no campaign
 * id, so every campaign would read whichever one's request warmed the
 * transient first. `ledger.campaign_id` is indexed; revisit only if this
 * specific query is ever shown to be slow.
 *
 * `skipped` and `estimate` ship `null` (their panels self-hide, see
 * CampaignPerformanceSidePanel.tsx): both would need replaying every order
 * through an engine that does not exist yet (Engine\Earning\EarningService
 * is scaffold — see its own docblock). Revisit once it is implemented.
 *
 * `totals.avg_order_subtotal` also ships `null` for the same "not
 * implemented yet" reason, one level down: nothing persists the scored
 * base amount an award was computed from (Engine\Points\Result /
 * Breakdown compute it, then discard it). If F01-4 stores it on the ledger
 * row, this and each entry's own `subtotal` both become a plain query
 * instead of a WooCommerce order lookup.
 */
final class CampaignPerformanceRestController extends AbstractRestController {

    /**
     * Triggers this page understands. Mirrors the SPA's
     * `CAMPAIGN_PERFORMANCE_TRIGGERS` (lib/schema/campaign-performance.schema.ts).
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

    /**
     * Shares the base of the resource it reports on, like
     * CampaignPreviewRestController — a sub-route of /campaigns, not a
     * resource of its own.
     */
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
     * The real campaign behind the trigger gate. Returns null — a plain 404
     * — for an unknown id AND for a known id whose trigger this page does
     * not support, so a client can't tell the two apart by response shape.
     */
    private function resolve_campaign( int $id ): ?Campaign {
        $campaign = $this->campaigns->find_campaign( $id );

        if ( null === $campaign || ! in_array( $campaign->get_trigger(), self::TRIGGERS, true ) ) {
            return null;
        }

        return $campaign;
    }

    /**
     * Lifetime `$totals` (see class docblock) into the wire shape — the
     * derived/nullable fields the repository's own aggregate can't produce
     * on its own: liability (needs the store's points-to-currency rate) and
     * "of N completed orders" (a sitewide WooCommerce count, not scoped to
     * this campaign).
     *
     * @param array<string, mixed> $row
     * @param array{points_issued: int, entries_total: int, orders_affected: int, customers_earned: int} $totals
     * @return array<string, mixed>
     */
    private function shape_totals( array $row, array $totals ): array {
        $shaped = [
            'points_issued'           => $totals['points_issued'],
            'entries_total'           => $totals['entries_total'],
            'orders_affected'         => null,
            'orders_total'            => null,
            'customers_earned'        => null,
            'avg_points_per_order'    => null,
            'avg_order_subtotal'      => null,
            'liability_amount'        => null,
            'liability_above_default' => null,
        ];

        if ( CampaignTrigger::PURCHASE !== $row['trigger'] ) {
            $shaped['customers_earned'] = $totals['customers_earned'];

            return $shaped;
        }

        $rate = $this->dashboard->points_per_currency_unit();

        $shaped['orders_affected']  = $totals['orders_affected'];
        $shaped['orders_total']     = $this->dashboard->count_orders( 0, time() + 1 );
        $shaped['liability_amount'] = PointsMath::points_to_currency( $totals['points_issued'], $rate, wc_get_price_decimals() );

        if ( $totals['orders_affected'] > 0 ) {
            $shaped['avg_points_per_order'] = intdiv( $totals['points_issued'], $totals['orders_affected'] );
        }

        $shaped['liability_above_default'] = $this->liability_above_default( $row, $totals['points_issued'], $rate );

        return $shaped;
    }

    /**
     * The part of `$points_issued` genuinely above what the default rate
     * would have awarded — only meaningful for a non-built-in multiplier
     * campaign (a rate-mode or built-in campaign IS the default, so there is
     * no "above" to report). Unchanged from CampaignPerformanceFixture's own
     * version of this — the arithmetic never depended on invented data,
     * only on this campaign's real config and its (now real) points_issued.
     *
     * @param array<string, mixed> $row
     */
    private function liability_above_default( array $row, int $points_issued, float $rate ): ?float {
        $config = (array) $row['config'];

        if ( ! PurchaseHandler::is_multiplier( $config ) || $row['is_builtin'] ) {
            return null;
        }

        $multiplier = PurchaseHandler::multiplier( $config );

        if ( $multiplier <= 0 ) {
            return null;
        }

        $default_equivalent = (int) round( $points_issued / $multiplier );

        return PointsMath::points_to_currency( $points_issued - $default_equivalent, $rate, wc_get_price_decimals() );
    }

    /**
     * The chart's own span: up to 30 site-timezone days trailing "now" (or
     * the campaign's end, if it already ended), clamped to never start
     * before the campaign itself did. See the class docblock ("TWO TIME
     * BASES ON PURPOSE") for why this differs from `totals`.
     *
     * @param array<string, mixed> $row
     * @return array{from: string, to: string} Y-m-d, site-tz, both ends inclusive.
     */
    private function chart_window( array $row ): array {
        $site_timezone = wp_timezone();
        $now           = ( new \DateTimeImmutable( 'now', $site_timezone ) )->setTime( 0, 0, 0 );

        $starts = $this->site_midnight( $row['starts_at'], $site_timezone );
        // ends_at is exclusive, so the last live day is the one holding the second before it — a 10:00 end keeps that morning.
        $last_day = $this->site_midnight( $row['ends_at'], $site_timezone, '-1 second' );

        $to = null !== $last_day && $last_day < $now ? $last_day : $now;

        $from = $to->modify( '-29 days' );

        if ( null !== $starts && $starts > $from ) {
            $from = $starts;
        }

        // A campaign scheduled to start after $to (not running yet, or a
        // same-day flash sale) — floor to a same-day, one-day window rather
        // than let $from > $to silently produce zero days.
        if ( $from > $to ) {
            $to = $from;
        }

        return [
            'from' => $from->format( 'Y-m-d' ),
            'to'   => $to->format( 'Y-m-d' ),
        ];
    }

    private function site_midnight( ?string $utc, \DateTimeZone $site_timezone, string $shift = '' ): ?\DateTimeImmutable {
        if ( null === $utc || '' === $utc ) {
            return null;
        }

        $date = date_create_immutable( $utc, new \DateTimeZone( 'UTC' ) );

        if ( false === $date ) {
            return null;
        }

        return ( '' === $shift ? $date : $date->modify( $shift ) )->setTimezone( $site_timezone )->setTime( 0, 0, 0 );
    }

    /**
     * `$window` (site-tz Y-m-d, inclusive) converted to the UTC bounds
     * CampaignPointsRepository::daily_by_campaign() needs, zero-filled by
     * DashboardMath::fill_series() exactly as the dashboard's own daily
     * series is.
     *
     * @param array{from: string, to: string} $window
     * @return array<int, array{date: string, points: int}>
     */
    private function daily_series( int $campaign_id, array $window ): array {
        $site_timezone = wp_timezone();

        $from = new \DateTimeImmutable( $window['from'] . ' 00:00:00', $site_timezone );
        $to   = ( new \DateTimeImmutable( $window['to'] . ' 00:00:00', $site_timezone ) )->modify( '+1 day' );

        $rows = $this->points->daily_by_campaign(
            Dates::to_utc( $from ),
            Dates::to_utc( $to ),
            $from->getOffset(),
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
     * No item schema: both routes return a report, not a resource — same
     * reasoning as CampaignPreviewRestController::define_item_schema().
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
