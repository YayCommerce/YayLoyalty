<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Pro\Repositories\CampaignPointsRepository;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Services\Dashboard\DashboardCache;
use YayLoyalty\Services\Dashboard\DashboardMath;
use YayLoyalty\Services\Dashboard\PeriodWindow;

defined( 'ABSPATH' ) || exit;

/**
 * GET /dashboard/pro: per-campaign issuance for the breakdown lines and Top
 * campaigns. referrals and tier_distribution are null until their tables
 * exist. Rest base is `dashboard-pro`, not `dashboard`, so a base-only
 * schema subscriber cannot match both payloads. Cached under the 'pro' variant.
 */
final class DashboardProRestController extends AbstractRestController {

    public const TOP_CAMPAIGNS = 3;

    /** @var CampaignPointsRepository */
    private $points;

    /** @var CampaignRepository */
    private $campaigns;

    /** @var DashboardCache */
    private $cache;

    public function __construct( CampaignPointsRepository $points, CampaignRepository $campaigns, DashboardCache $cache ) {
        $this->points    = $points;
        $this->campaigns = $campaigns;
        $this->cache     = $cache;
    }

    public function get_rest_base(): string {
        return 'dashboard-pro';
    }

    public function register_routes(): void {
        $args = [
            'range' => [
                'type'    => 'integer',
                'enum'    => PeriodWindow::RANGES,
                'default' => 30,
            ],
        ];

        register_rest_route(
            self::REST_NAMESPACE,
            '/dashboard/pro',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_pro_overview' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => $args,
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_pro_overview( \WP_REST_Request $request ): array {
        $range = (int) $request['range'];

        $window = PeriodWindow::for_range( $range, new \DateTimeImmutable( 'now', wp_timezone() ) );

        $payload = $this->cache->remember( $range, fn(): array => $this->build( $window ), 'pro' );

        // The Free payload owns the cache strip.
        unset( $payload['cached'], $payload['generated_at'], $payload['next_refresh_at'] );

        return [ 'range' => $range ] + $payload;
    }

    /**
     * @return array{referrals: null, tier_distribution: null, campaign_series: array, top_campaigns: array}
     */
    private function build( PeriodWindow $window ): array {
        // Over-fetch: a deleted campaign's points must not consume one of the three slots.
        $top_campaigns = [];

        foreach ( $this->points->issued_by_campaign( $window->from_utc(), $window->to_utc(), self::TOP_CAMPAIGNS * 2 ) as $entry ) {
            $campaign = $this->campaigns->find_campaign( $entry['campaign_id'] );

            if ( null === $campaign ) {
                continue;
            }

            $top_campaigns[] = [
                'campaign_id' => $entry['campaign_id'],
                'name'        => $campaign->get_name(),
                'points'      => $entry['points'],
                'trigger'     => $campaign->get_trigger(),
                'priority'    => $campaign->get_priority(),
                'config'      => $campaign->get_config(),
                'conditions'  => $campaign->get_conditions(),
            ];

            if ( count( $top_campaigns ) === self::TOP_CAMPAIGNS ) {
                break;
            }
        }

        $ids    = array_column( $top_campaigns, 'campaign_id' );
        $rows   = $this->points->daily_by_campaign( $window->from_utc(), $window->to_utc(), $window->utc_offset_seconds(), $ids );
        $series = [];

        foreach ( $ids as $id ) {
            $own = array_values( array_filter( $rows, static fn( array $r ): bool => (int) $r['campaign_id'] === $id ) );

            foreach ( DashboardMath::fill_series( $own, $window->days(), [ 'issued' ] ) as $point ) {
                $series[] = $point + [ 'campaign_id' => $id ];
            }
        }

        return [
            'referrals'         => null,
            'tier_distribution' => null,
            'campaign_series'   => $series,
            'top_campaigns'     => $top_campaigns,
        ];
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_dashboard_pro',
            'type'       => 'object',
            'properties' => [
                'range'             => [
                    'type'     => 'integer',
                    'enum'     => PeriodWindow::RANGES,
                    'readonly' => true,
                ],
                'referrals'         => [
                    'type'        => [ 'object', 'null' ],
                    'readonly'    => true,
                    'description' => __( 'Null until referrals ship.', 'yayloyalty' ),
                ],
                'tier_distribution' => [
                    'type'        => [ 'array', 'null' ],
                    'readonly'    => true,
                    'description' => __( 'Null until tiers ship.', 'yayloyalty' ),
                ],
                'campaign_series'   => [
                    'type'        => 'array',
                    'readonly'    => true,
                    'description' => __( 'One row per day per top campaign: date, campaign_id, issued.', 'yayloyalty' ),
                ],
                'top_campaigns'     => [
                    'type'        => 'array',
                    'readonly'    => true,
                    'description' => __( 'Up to three campaigns by points issued in the range, with the fields the admin app needs to describe them.', 'yayloyalty' ),
                ],
            ],
        ];
    }
}
