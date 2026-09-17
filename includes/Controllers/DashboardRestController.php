<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Services\Dashboard\DashboardCache;
use YayLoyalty\Services\Dashboard\DashboardNotices;
use YayLoyalty\Services\Dashboard\DashboardStats;
use YayLoyalty\Services\Dashboard\NoticeDismissals;
use YayLoyalty\Services\Dashboard\PeriodWindow;

defined( 'ABSPATH' ) || exit;

/**
 * GET /yayloyalty/v1/dashboard — the admin landing page's single payload.
 *
 * Read-only aggregates over the ledger, customers and WooCommerce orders,
 * cached site-wide above DashboardCache::THRESHOLD members. `notices` is
 * structured data only ({key, severity, fingerprint, data}); every word of
 * copy is composed in the SPA. Raw notices live in the shared cache; the
 * per-user dismissal filter runs after it, so nothing per-user is ever
 * cached. POST /dashboard/notices/dismiss is the one write, and it takes
 * the user from the session, never from the request.
 *
 * `scenario` exists only when WP_DEBUG is on: it swaps the whole payload for
 * DashboardFixture data so every UI state can be reviewed on a dev store.
 * The argument is not registered otherwise, so a production request
 * carrying it is simply ignored.
 */
final class DashboardRestController extends AbstractRestController {

    /** @var DashboardStats */
    private $stats;

    /** @var DashboardCache */
    private $cache;

    /** @var DashboardNotices */
    private $notices;

    /** @var NoticeDismissals */
    private $dismissals;

    public function __construct( DashboardStats $stats, DashboardCache $cache, DashboardNotices $notices, NoticeDismissals $dismissals ) {
        $this->stats      = $stats;
        $this->cache      = $cache;
        $this->notices    = $notices;
        $this->dismissals = $dismissals;
    }

    public function get_rest_base(): string {
        return 'dashboard';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/dashboard',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_overview' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => $this->overview_args(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/dashboard/notices/dismiss',
            [
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'dismiss_notice' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => [
                        // An explicit sanitize_callback replaces WP's default
                        // rest_parse_request_arg, which is what enforces enum
                        // and maxLength — so validation is named explicitly.
                        'key'         => [
                            'type'              => 'string',
                            'required'          => true,
                            'enum'              => NoticeDismissals::DISMISSABLE,
                            'validate_callback' => 'rest_validate_request_arg',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'fingerprint' => [
                            'type'              => 'string',
                            'required'          => true,
                            'maxLength'         => 64,
                            'validate_callback' => 'rest_validate_request_arg',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function overview_args(): array {
        $args = [
            'range'   => [
                'type'    => 'integer',
                'enum'    => PeriodWindow::RANGES,
                'default' => 30,
            ],
            'refresh' => [
                'type'    => 'boolean',
                'default' => false,
            ],
        ];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $args['scenario'] = [
                'type'              => 'string',
                'enum'              => DashboardFixture::SCENARIOS,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ];
        }

        return $args;
    }

    public function get_overview( \WP_REST_Request $request ): array {
        $range = (int) $request['range'];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $request['scenario'] ) ) {
            return DashboardFixture::scenario( (string) $request['scenario'], $range );
        }

        if ( $request['refresh'] ) {
            DashboardCache::flush();
        }

        $now     = new \DateTimeImmutable( 'now', wp_timezone() );
        $window  = PeriodWindow::for_range( $range, $now );
        $payload = $this->cache->remember(
            $range,
            fn(): array => $this->stats->build( $window ) + [ 'notices' => $this->notices->collect( $now ) ]
        );

        // Per-user step, deliberately outside the cache.
        $payload['notices'] = $this->dismissals->filter( $payload['notices'], get_current_user_id() );

        return [ 'range' => $range ] + $payload;
    }

    public function dismiss_notice( \WP_REST_Request $request ): array {
        $this->dismissals->dismiss( get_current_user_id(), (string) $request['key'], (string) $request['fingerprint'] );

        return [
            'key'         => (string) $request['key'],
            'fingerprint' => (string) $request['fingerprint'],
        ];
    }

    protected function define_item_schema(): array {
        $stat = [
            'type'       => 'object',
            'readonly'   => true,
            'properties' => [
                'value'          => [ 'type' => 'integer' ],
                'prev_delta_pct' => [ 'type' => [ 'number', 'null' ] ],
            ],
        ];

        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_dashboard',
            'type'       => 'object',
            // Everything is derived; nothing here is ever written back.
            'properties' => [
                'range'           => [
                    'type'     => 'integer',
                    'enum'     => PeriodWindow::RANGES,
                    'readonly' => true,
                ],
                'generated_at'    => [
                    'type'     => 'string',
                    'readonly' => true,
                ],
                'cached'          => [
                    'type'     => 'boolean',
                    'readonly' => true,
                ],
                'next_refresh_at' => [
                    'type'     => [ 'string', 'null' ],
                    'readonly' => true,
                ],
                'stats'           => [
                    'type'       => 'object',
                    'readonly'   => true,
                    'properties' => [
                        'members'         => $stat,
                        'points_issued'   => $stat,
                        'points_redeemed' => $stat,
                        'liability'       => [
                            'type'       => 'object',
                            'properties' => [
                                'points'                   => [ 'type' => 'integer' ],
                                'currency_amount'          => [ 'type' => [ 'number', 'null' ] ],
                                'points_per_currency_unit' => [ 'type' => 'number' ],
                                'prev_delta_pct'           => [ 'type' => [ 'number', 'null' ] ],
                            ],
                        ],
                        'redemption_rate' => [
                            'type'       => 'object',
                            'properties' => [
                                'rate'               => [ 'type' => [ 'number', 'null' ] ],
                                'orders_with_points' => [ 'type' => 'integer' ],
                                'orders_total'       => [ 'type' => 'integer' ],
                                'prev_delta_pct'     => [ 'type' => [ 'number', 'null' ] ],
                            ],
                        ],
                    ],
                ],
                'series'          => [
                    'type'        => 'array',
                    'readonly'    => true,
                    'description' => __( 'One row per site-timezone calendar day in the range.', 'yayloyalty' ),
                ],
                'top_earners'     => [
                    'type'     => 'array',
                    'readonly' => true,
                ],
                'recent_activity' => [
                    'type'     => 'object',
                    'readonly' => true,
                ],
                'notices'         => [
                    'type'        => 'array',
                    'readonly'    => true,
                    'description' => __( 'Structured only: key, severity, fingerprint, data. The admin app composes the copy.', 'yayloyalty' ),
                ],
            ],
        ];
    }
}
