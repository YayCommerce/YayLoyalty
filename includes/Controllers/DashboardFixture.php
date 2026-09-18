<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Services\Dashboard\AnomalyDetector;
use YayLoyalty\Services\Dashboard\PeriodWindow;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Dev-only dashboard scenarios, served for `?scenario=` under WP_DEBUG: a
 * dev ledger is too small to review the UI states against. Every scenario
 * emits the exact wire shape the SPA parses, so drift fails loudly. Deleted
 * with both `scenario` args once signed off; nothing in the SPA changes.
 */
final class DashboardFixture {

    public const SCENARIOS = [ 'empty', 'small', 'large', 'notices', 'anomaly' ];

    public static function scenario( string $name, int $range ): array {
        $window = PeriodWindow::for_range( $range, new \DateTimeImmutable( 'now', wp_timezone() ) );
        $days   = $window->days();

        switch ( $name ) {
            case 'empty':
                return self::envelope( $range, self::stats( 0, 0, 0, 0, 100, 0, 0, null ), self::series( $days, 0, 0 ), [], [], 0 );
            case 'large':
                return array_replace(
                    self::envelope( $range, self::stats( 250000, 18420000, 6140000, 87150000, 100, 21200, 119000 ), self::series( $days, 614000, 204700 ), self::earners(), self::activity(), 41200 ),
                    [
                        'cached'          => true,
                        'next_refresh_at' => Dates::offset( 5 * MINUTE_IN_SECONDS ),
                    ]
                );
            case 'notices':
                return array_replace( self::envelope( $range, self::small_stats(), self::series( $days, 6120, 2040 ), self::earners(), self::activity(), 14 ), [ 'notices' => self::notices() ] );
            case 'anomaly':
                return array_replace(
                    self::envelope( $range, self::small_stats(), self::series( $days, 6120, 2040, $days[ count( $days ) - 2 ] ), self::earners(), self::activity(), 14 ),
                    [ 'notices' => [ self::points_jump( $days ) ] ]
                );
            default:
                return self::envelope( $range, self::small_stats(), self::series( $days, 6120, 2040 ), self::earners(), self::activity(), 14 );
        }
    }

    /** Pro panel data for the same scenarios; referrals and tier_distribution stay null, as on the real path. */
    public static function pro_scenario( string $name, int $range ): array {
        $window = PeriodWindow::for_range( $range, new \DateTimeImmutable( 'now', wp_timezone() ) );
        $days   = $window->days();
        $empty  = 'empty' === $name;
        $scale  = 'large' === $name ? 100 : 1;

        $campaigns = [
            [
                1,
                'Purchase points',
                'purchase',
                121400,
                [
                    'mode'            => 'rate',
                    'points_per_unit' => 1,
                ],
                0,
            ],
            [
                7,
                'Summit+ members 1.5×',
                'purchase',
                38600,
                [
                    'mode'       => 'multiplier',
                    'multiplier' => 1.5,
                ],
                10,
            ],
            [
                9,
                'Trail Shoes boost',
                'purchase',
                14900,
                [
                    'mode'       => 'multiplier',
                    'multiplier' => 3,
                ],
                20,
            ],
        ];

        $series = [];
        $top    = [];

        foreach ( $campaigns as $i => $c ) {
            if ( $empty ) {
                break;
            }

            foreach ( self::series( $days, (int) ( $c[3] / 30 * $scale ), 0 ) as $point ) {
                $series[] = [
                    'date'        => $point['date'],
                    'campaign_id' => $c[0],
                    'issued'      => $point['issued'],
                ];
            }

            $top[] = [
                'campaign_id' => $c[0],
                'name'        => $c[1],
                'points'      => $c[3] * $scale,
                'trigger'     => $c[2],
                'priority'    => $c[5],
                'config'      => $c[4],
                'conditions'  => 0 === $i ? [] : [ [ 'type' => 0 === $i % 2 ? 'product_in_cart' : 'tier' ] ],
            ];
        }

        return [
            'range'             => $range,
            'referrals'         => null,
            'tier_distribution' => null,
            'campaign_series'   => $series,
            'top_campaigns'     => $top,
        ];
    }

    private static function envelope( int $range, array $stats, array $series, array $earners, array $rows, int $total_today ): array {
        return [
            'range'           => $range,
            'generated_at'    => Dates::now(),
            'cached'          => false,
            'next_refresh_at' => null,
            'stats'           => $stats,
            'series'          => $series,
            'top_earners'     => $earners,
            'recent_activity' => [
                'total_today' => $total_today,
                'rows'        => $rows,
            ],
            'notices'         => [],
        ];
    }

    private static function small_stats(): array {
        return self::stats( 2431, 184200, 61400, 871500, 100, 212, 1190 );
    }

    /**
     * @param float|null $delta Null → every delta null ("no previous period").
     */
    private static function stats( int $members, int $issued, int $redeemed, int $liability, int $ppcu, int $with_points, int $orders, ?float $delta = 1.0 ): array {
        $d = static fn( float $value ): ?float => null === $delta ? null : $value;

        return [
            'members'         => [
                'value'          => $members,
                'prev_delta_pct' => $d( 3.2 ),
            ],
            'points_issued'   => [
                'value'          => $issued,
                'prev_delta_pct' => $d( 8.1 ),
            ],
            'points_redeemed' => [
                'value'          => $redeemed,
                'prev_delta_pct' => $d( 12.4 ),
            ],
            'liability'       => [
                'points'                   => $liability,
                'currency_amount'          => $ppcu > 0 ? round( $liability / $ppcu, 2 ) : null,
                'points_per_currency_unit' => $ppcu,
                'prev_delta_pct'           => $d( 4.6 ),
            ],
            'redemption_rate' => [
                'rate'               => $orders > 0 ? round( $with_points / $orders * 100, 1 ) : null,
                'orders_with_points' => $with_points,
                'orders_total'       => $orders,
                'prev_delta_pct'     => $orders > 0 ? $d( -0.9 ) : null,
            ],
        ];
    }

    /**
     * Deterministic daily figures: a slow sine plus seeded noise, identical on every load.
     *
     * @param string[] $days
     * @param string   $spike_on Day to lift to the anomaly figure, if any.
     */
    private static function series( array $days, int $issued_avg, int $redeemed_avg, string $spike_on = '' ): array {
        $seed   = 7;
        $series = [];

        foreach ( $days as $i => $date ) {
            $seed  = ( $seed * 1103515245 + 12345 ) % 2147483648;
            $noise = ( $seed % 1000 ) / 1000 - 0.5;
            $wave  = 1 + 0.25 * sin( $i / 3.1 ) + 0.2 * $noise;

            $series[] = [
                'date'     => $date,
                'issued'   => $date === $spike_on ? 41200 : (int) round( $issued_avg * $wave ),
                'redeemed' => (int) round( $redeemed_avg * ( 2 - $wave ) ),
            ];
        }

        return $series;
    }

    private static function earners(): array {
        return [
            [
                'user_id' => 12,
                'name'    => 'Amara Okafor',
                'balance' => 24900,
            ],
            [
                'user_id' => 7,
                'name'    => 'Lukas Meier',
                'balance' => 18650,
            ],
            [
                'user_id' => 31,
                'name'    => 'Priya Raman',
                'balance' => 15300,
            ],
        ];
    }

    private static function activity(): array {
        $rows = [
            [ 6, 12, 340, 'earn', 'order', 'order', 1042, 'Amara Okafor', 12 ],
            [ 5, 7, -1500, 'redeem', 'order', 'order', 1041, 'Lukas Meier', 25 ],
            [ 4, 31, 120, 'earn', 'review', 'review', 88, 'Priya Raman', 61 ],
            [ 3, 44, 200, 'earn', 'signup', null, null, 'Noah Kim', 140 ],
            [ 2, 12, -250, 'refund_earn', 'order', 'order', 1039, 'Amara Okafor', 210 ],
            [ 1, 19, 500, 'adjust', 'manual', null, null, 'Sofia Rossi', 260 ],
        ];

        return array_map(
            static fn( array $r ): array => [
                'id'             => $r[0],
                'user_id'        => $r[1],
                'points'         => $r[2],
                'balance_after'  => max( 0, 1000 + $r[2] ),
                'type'           => $r[3],
                'source'         => $r[4],
                'reference_type' => $r[5],
                'reference_id'   => $r[6],
                'campaign_id'    => 'earn' === $r[3] ? 1 : null,
                'redemption_id'  => null,
                'order_id'       => 'order' === $r[5] ? $r[6] : null,
                'note'           => 'adjust' === $r[3] ? 'Goodwill after late delivery' : null,
                'actor_id'       => 'adjust' === $r[3] ? 1 : null,
                'created_at'     => Dates::offset( -60 * $r[8] ),
                'name'           => $r[7],
            ],
            $rows
        );
    }

    /** Every notice key and severity, in server order. */
    private static function notices(): array {
        $paused_at = Dates::offset( -8 * DAY_IN_SECONDS );

        return [
            [
                'key'         => 'default_campaign_paused',
                'severity'    => 'affected',
                'fingerprint' => $paused_at,
                'data'        => [
                    'campaign_id'           => 1,
                    'paused_since'          => $paused_at,
                    'orders_without_points' => 47,
                ],
            ],
            [
                'key'         => 'mail_failures',
                'severity'    => 'waiting',
                'fingerprint' => 'failed:12',
                'data'        => [
                    'failed' => 12,
                    'since'  => Dates::offset( -2 * DAY_IN_SECONDS ),
                ],
            ],
            [
                'key'         => 'negative_balance',
                'severity'    => 'waiting',
                'fingerprint' => 'count:2-9',
                'data'        => [ 'count' => 3 ],
            ],
            [
                'key'         => 'balance_rebuild',
                'severity'    => 'progress',
                'fingerprint' => 'run:2026-09-16',
                'data'        => [
                    'processed' => 1840,
                    'total'     => 2431,
                ],
            ],
        ];
    }

    /**
     * Yesterday spiked to 41,200 against a 6,100 average.
     *
     * @param string[] $days
     */
    private static function points_jump( array $days ): array {
        $yesterday = $days[ count( $days ) - 2 ];
        $daily     = [];

        for ( $i = 12; $i >= 0; $i-- ) {
            $date    = ( new \DateTimeImmutable( $yesterday ) )->modify( "-{$i} days" )->format( 'Y-m-d' );
            $daily[] = [
                'date'   => $date,
                'issued' => 0 === $i ? 41200 : 5400 + ( ( $i * 37 ) % 1400 ),
            ];
        }

        return [
            'key'         => 'points_jump',
            'severity'    => 'affected',
            'fingerprint' => $yesterday,
            'data'        => [
                'yesterday'        => $yesterday,
                'yesterday_points' => 41200,
                'daily_average'    => 6100,
                'multiple'         => 6.8,
                'daily'            => $daily,
                'threshold'        => [
                    'multiple'   => AnomalyDetector::MULTIPLE,
                    'min_orders' => AnomalyDetector::MIN_ORDERS,
                ],
                'orders_admin_url' => admin_url( 'admin.php?page=wc-orders&m=' . substr( str_replace( '-', '', $yesterday ), 0, 6 ) ),
                'what_changed'     => [
                    [
                        'campaign_id'     => 1,
                        'name'            => 'Purchase points',
                        'updated_at'      => $yesterday . ' 02:14:00',
                        'updated_by_name' => 'Dana',
                        'orders_affected' => 214,
                        'points_issued'   => 41200,
                    ],
                ],
            ],
        ];
    }
}
