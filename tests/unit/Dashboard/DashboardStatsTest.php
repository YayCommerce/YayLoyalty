<?php
namespace YayLoyalty\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Services\Dashboard\DashboardStats;
use YayLoyalty\Services\Dashboard\PeriodWindow;

/**
 * DashboardStats composes repository reads: ratios (redemption rate, prev
 * deltas) go through Utils\PointsMath, and DashboardMath only fills the
 * series' missing days. The stub answers every read from fixed numbers keyed
 * on the window it was asked about, so the assertions pin which window feeds
 * which figure.
 */
final class DashboardStatsTest extends TestCase {

    public const FROM      = '2026-08-17 17:00:00';
    public const TO        = '2026-09-16 17:00:00';
    public const PREV_FROM = '2026-07-18 17:00:00';

    /**
     * @param array<string, mixed> $overrides Method name => return value.
     */
    private function repository( array $overrides = [] ): DashboardRepository {
        return new class( $overrides ) extends DashboardRepository {
            /** @var array<string, mixed> */
            private array $o;

            public function __construct( array $overrides ) {
                $this->o = $overrides;
            }

            public function count_members( string $before_utc ): int {
                return DashboardStatsTest::TO === $before_utc ? 2431 : 2355;
            }

            public function sum_issued( string $from, string $to ): int {
                return DashboardStatsTest::FROM === $from ? 184200 : 170400;
            }

            public function sum_redeemed( string $from, string $to ): int {
                return DashboardStatsTest::FROM === $from ? 61400 : 54600;
            }

            public function net_delta( string $from, string $to ): int {
                return $this->o['net_delta'] ?? 38300;
            }

            public function daily_points( string $from, string $to, int $offset_seconds ): array {
                return [
                    [
                        'date'     => '2026-09-16',
                        'issued'   => '6120',
                        'redeemed' => '2040',
                    ],
                ];
            }

            public function liability_points(): int {
                return 871500;
            }

            public function net_balance(): int {
                return $this->o['net_balance'] ?? 871500;
            }

            public function orders_with_points( string $from, string $to ): int {
                return $this->o['orders_with_points'] ?? ( DashboardStatsTest::FROM === $from ? 212 : 190 );
            }

            public function count_orders( int $from_ts, int $to_ts ): int {
                return $this->o['count_orders'] ?? ( strtotime( DashboardStatsTest::FROM . ' UTC' ) === $from_ts ? 1190 : 1016 );
            }

            public function top_earners( int $limit ): array {
                return [
                    [
                        'user_id' => 7,
                        'name'    => 'Marta',
                        'balance' => 24900,
                    ],
                ];
            }

            public function latest_activity( int $limit ): array {
                return [];
            }

            public function count_activity_since( string $from_utc ): int {
                return DashboardStatsTest::PREV_FROM !== $from_utc && '2026-09-15 17:00:00' === $from_utc ? 41 : -1;
            }

            public function points_per_currency_unit(): float {
                return $this->o['ppcu'] ?? 100.0;
            }
        };
    }

    private function window(): PeriodWindow {
        return PeriodWindow::for_range( 30, new \DateTimeImmutable( '2026-09-16 08:00:00', new \DateTimeZone( 'Asia/Saigon' ) ) );
    }

    public function test_stats_and_deltas(): void {
        $stats = ( new DashboardStats( $this->repository(), 2 ) )->build( $this->window() )['stats'];

        $this->assertSame(
            [
                'value'          => 2431,
                'prev_delta_pct' => 3.2,
            ],
            $stats['members']
        );
        $this->assertSame(
            [
                'value'          => 184200,
                'prev_delta_pct' => 8.1,
            ],
            $stats['points_issued']
        );
        $this->assertSame(
            [
                'value'          => 61400,
                'prev_delta_pct' => 12.5,
            ],
            $stats['points_redeemed']
        );
        $this->assertSame( 17.8, $stats['redemption_rate']['rate'] );
        $this->assertSame( 212, $stats['redemption_rate']['orders_with_points'] );
        $this->assertSame( 1190, $stats['redemption_rate']['orders_total'] );
        // 190 / 1016 = 18.7 → 17.8 is a 4.8% fall.
        $this->assertSame( -4.8, $stats['redemption_rate']['prev_delta_pct'] );
    }

    public function test_liability_uses_net_balance_minus_net_delta_as_previous(): void {
        $liability = ( new DashboardStats(
            $this->repository(
                [
                    'net_balance' => 871500,
                    'net_delta'   => 38300,
                ]
            ),
            2
        ) )->build( $this->window() )['stats']['liability'];

        // previous ≈ 871500 − 38300 = 833200 → +4.6%.
        $this->assertSame( 871500, $liability['points'] );
        $this->assertSame( 8715.0, $liability['currency_amount'] );
        $this->assertSame( 100.0, $liability['points_per_currency_unit'] );
        $this->assertSame( 4.6, $liability['prev_delta_pct'] );
    }

    public function test_liability_currency_is_null_without_a_rate(): void {
        $liability = ( new DashboardStats( $this->repository( [ 'ppcu' => 0.0 ] ), 2 ) )->build( $this->window() )['stats']['liability'];

        $this->assertNull( $liability['currency_amount'] );
        $this->assertSame( 0.0, $liability['points_per_currency_unit'] );
    }

    public function test_rate_is_null_without_orders(): void {
        $rate = ( new DashboardStats(
            $this->repository(
                [
                    'count_orders'       => 0,
                    'orders_with_points' => 0,
                ]
            ),
            2
        ) )->build( $this->window() )['stats']['redemption_rate'];

        $this->assertNull( $rate['rate'] );
        $this->assertNull( $rate['prev_delta_pct'] );
        $this->assertSame( 0, $rate['orders_total'] );
    }

    public function test_series_is_zero_filled_to_the_range(): void {
        $series = ( new DashboardStats( $this->repository(), 2 ) )->build( $this->window() )['series'];

        $this->assertCount( 30, $series );
        $this->assertSame(
            [
                'date'     => '2026-08-18',
                'issued'   => 0,
                'redeemed' => 0,
            ],
            $series[0]
        );
        $this->assertSame(
            [
                'date'     => '2026-09-16',
                'issued'   => 6120,
                'redeemed' => 2040,
            ],
            $series[29]
        );
    }

    public function test_lists_and_today_count(): void {
        $payload = ( new DashboardStats( $this->repository(), 2 ) )->build( $this->window() );

        $this->assertSame(
            [
                [
                    'user_id' => 7,
                    'name'    => 'Marta',
                    'balance' => 24900,
                ],
            ],
            $payload['top_earners']
        );
        // Today in Saigon starts 2026-09-15 17:00 UTC.
        $this->assertSame( 41, $payload['recent_activity']['total_today'] );
        $this->assertSame( [], $payload['recent_activity']['rows'] );
    }
}
