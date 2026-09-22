<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Controllers\CampaignRestController;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Repositories\DashboardRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro-only: a single campaign's performance —
 * `GET /campaigns/{id}/performance` (totals, daily chart, "where the other
 * orders went", the pre-launch estimate) and
 * `GET /campaigns/{id}/performance/entries` (the paged, searchable row list
 * behind it: orders / signups / reviews depending on trigger).
 *
 * Supports `purchase`, `signup` and `review` only (self::TRIGGERS) — the
 * other triggers have no wireframe and no fixture data; resolve_row() 404s
 * for them exactly as it does for an unknown id, so a merchant clicking a
 * link this build never renders and one typing a stray URL see the same
 * thing.
 *
 * Registered through `yayloyalty_rest_controllers`, so Lite has no route
 * here at all (404, not 403) — same as CampaignPreviewRestController.
 *
 * SWAP POINT. Both handlers answer from CampaignPerformanceFixture, which
 * is deterministic per campaign id but invented, exactly like
 * CampaignRestController::dummy_fixture(). When real per-campaign analytics
 * are ready:
 *  - get_performance() calls a new CampaignPerformanceRepository aggregate
 *    over `ledger` rows carrying this campaign_id (`earn` type), plus a
 *    WooCommerce order count for orders_in_window; wrap it in DashboardCache
 *    the way DashboardProRestController does, since this is a per-request
 *    aggregate over a table that can be large. `skipped` and `estimate` may
 *    ship as `null` at first — both need re-evaluating every order in the
 *    window (skipped) or the same week a year prior (estimate), and the SPA
 *    already hides each panel on `null`.
 *  - get_entries() calls a paged `ledger` query,
 *    `WHERE campaign_id = ? ORDER BY created_at DESC, id DESC`, joined to
 *    `customers` for the name/search, run through respond_paginated()
 *    exactly as here.
 *  - CampaignPerformanceFixture is deleted; resolve_row() keeps using
 *    find_dummy_row() until CampaignRestController's own read-side SWAP
 *    POINT is resolved separately.
 * Nothing changes on the SPA side either way: both routes are already real
 * HTTP calls parsed by lib/schema/campaign-performance.schema.ts.
 *
 * INVARIANT the real aggregate must preserve: `totals.entries_total` is 0 for
 * a campaign that hasn't started yet (no ledger row can predate its own
 * campaign's `starts_at`). The SPA's empty-state banner
 * (CampaignPerformance.tsx) derives `hasData` from `entries_total` alone and
 * relies on a `scheduled` campaign never having real data to show — it does
 * not separately guard against showing both the banner and populated tiles at
 * once.
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

    public function __construct( DashboardRepository $dashboard ) {
        $this->dashboard = $dashboard;
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
        $row = $this->resolve_row( (int) $request['id'] );

        if ( null === $row ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        return CampaignPerformanceFixture::summary( $row, $this->dashboard->points_per_currency_unit() );
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function get_entries( \WP_REST_Request $request ) {
        $row = $this->resolve_row( (int) $request['id'] );

        if ( null === $row ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        $result = CampaignPerformanceFixture::entries(
            $row,
            (int) $request['page'],
            (int) $request['per_page'],
            (string) $request['search']
        );

        return $this->respond_paginated( $result );
    }

    /**
     * find_dummy_row() plus the trigger gate. Returns null — a plain 404 —
     * for an unknown id AND for a known id whose trigger this page does not
     * support, so a client can't tell the two apart by response shape.
     *
     * @return array<string, mixed>|null
     */
    private function resolve_row( int $id ): ?array {
        $row = CampaignRestController::find_dummy_row( $id );

        if ( null === $row || ! in_array( $row['trigger'], self::TRIGGERS, true ) ) {
            return null;
        }

        return $row;
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
