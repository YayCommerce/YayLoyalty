<?php
namespace YayLoyalty\Tests\Unit\Dashboard;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Services\Dashboard\DashboardNotices;

/**
 * The three detectors are pure over injected reads; WordPress is reached
 * only through two protected methods the test overrides. Output carries no
 * copy — the SPA composes every word from `key` + `data`.
 */
final class DashboardNoticesTest extends TestCase {

    /** Site "now": 2026-09-16 08:00 Asia/Saigon → yesterday is 2026-09-15. */
    public const NOW       = '2026-09-16 08:00:00';
    public const YESTERDAY = '2026-09-15';

    /**
     * @param array<string, mixed> $o negatives, spike (bool), yesterday_orders, orders (paused window), orders_with_earn
     */
    private function repository( array $o = [] ): DashboardRepository {
        return new class( $o ) extends DashboardRepository {
            /** @var array<string, mixed> */
            private array $o;

            public function __construct( array $o ) {
                $this->o = $o;
            }

            public function count_negative_balances(): int {
                return $this->o['negatives'] ?? 0;
            }

            public function daily_points( string $from, string $to, int $offset_seconds ): array {
                $rows = [];
                $day  = new \DateTimeImmutable( '2026-08-18' );

                for ( $i = 0; $i < 30; $i++ ) {
                    $date   = $day->format( 'Y-m-d' );
                    $rows[] = [
                        'date'     => $date,
                        'issued'   => DashboardNoticesTest::YESTERDAY === $date && ! empty( $this->o['spike'] ) ? '41200' : '6100',
                        'redeemed' => '0',
                    ];
                    $day    = $day->modify( '+1 day' );
                }

                return $rows;
            }

            public function count_orders( int $from_ts, int $to_ts ): int {
                // Yesterday's window is exactly one day long; the paused window is not.
                return 86400 === $to_ts - $from_ts ? ( $this->o['yesterday_orders'] ?? 214 ) : ( $this->o['orders'] ?? 0 );
            }

            public function orders_with_earn( string $from, string $to ): int {
                return $this->o['orders_with_earn'] ?? 0;
            }

            public function campaign_points_on_day( int $campaign_id, string $from, string $to ): array {
                return [
                    'orders' => 214,
                    'points' => 41200,
                ];
            }
        };
    }

    /**
     * @param Campaign[] $campaigns
     */
    private function campaigns( array $campaigns ): CampaignRepository {
        return new class( $campaigns ) extends CampaignRepository {
            /** @var Campaign[] */
            private array $rows;

            public function __construct( array $rows ) {
                $this->rows = $rows;
            }

            public function all_non_archived(): array {
                return $this->rows;
            }

            public function find_default_purchase(): ?Campaign {
                foreach ( $this->rows as $c ) {
                    if ( CampaignRepository::DEFAULT_PURCHASE_SLUG === $c->get_slug() ) {
                        return $c;
                    }
                }

                return null;
            }
        };
    }

    private function campaign( int $id, string $slug, string $status, string $updated_at, ?int $updated_by = null ): Campaign {
        return new Campaign( $id, 'Campaign ' . $id, $slug, CampaignTrigger::PURCHASE, $status, 0, [], [], null, null, false, null, $updated_at, $updated_at, $updated_by );
    }

    private function notices( DashboardRepository $repo, array $campaigns = [] ): DashboardNotices {
        return new class( $repo, $this->campaigns( $campaigns ) ) extends DashboardNotices {
            protected function user_display_name( int $user_id ): string {
                return 'User ' . $user_id;
            }

            protected function orders_admin_url( string $month ): string {
                return 'orders://' . $month;
            }
        };
    }

    private function now(): \DateTimeImmutable {
        return new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'Asia/Saigon' ) );
    }

    /**
     * @return array<string, array> keyed by notice key
     */
    private function collect( DashboardRepository $repo, array $campaigns = [] ): array {
        $out = [];

        foreach ( $this->notices( $repo, $campaigns )->collect( $this->now() ) as $notice ) {
            $out[ $notice['key'] ] = $notice;
        }

        return $out;
    }

    public function test_no_negative_balances_no_notice(): void {
        $this->assertArrayNotHasKey( 'negative_balance', $this->collect( $this->repository() ) );
    }

    public function test_negative_balance_buckets(): void {
        $one = $this->collect( $this->repository( [ 'negatives' => 1 ] ) )['negative_balance'];
        $this->assertSame( 'waiting', $one['severity'] );
        $this->assertSame( 'count:1', $one['fingerprint'] );
        $this->assertSame( [ 'count' => 1 ], $one['data'] );

        $this->assertSame( 'count:2-9', $this->collect( $this->repository( [ 'negatives' => 3 ] ) )['negative_balance']['fingerprint'] );
        $this->assertSame( 'count:10-99', $this->collect( $this->repository( [ 'negatives' => 42 ] ) )['negative_balance']['fingerprint'] );
        $this->assertSame( 'count:100+', $this->collect( $this->repository( [ 'negatives' => 1284 ] ) )['negative_balance']['fingerprint'] );
    }

    public function test_active_default_campaign_no_notice(): void {
        $campaigns = [ $this->campaign( 1, CampaignRepository::DEFAULT_PURCHASE_SLUG, Status::ACTIVE, '2026-09-01 00:00:00' ) ];

        $this->assertArrayNotHasKey( 'default_campaign_paused', $this->collect( $this->repository(), $campaigns ) );
    }

    public function test_paused_default_campaign(): void {
        $campaigns = [ $this->campaign( 1, CampaignRepository::DEFAULT_PURCHASE_SLUG, Status::PAUSED, '2026-09-08 10:30:00' ) ];
        $notice    = $this->collect(
            $this->repository(
                [
                    'orders'           => 60,
                    'orders_with_earn' => 13,
                ]
            ),
            $campaigns
        )['default_campaign_paused'];

        $this->assertSame( 'affected', $notice['severity'] );
        $this->assertSame( '2026-09-08 10:30:00', $notice['fingerprint'] );
        $this->assertSame(
            [
                'campaign_id'           => 1,
                'paused_since'          => '2026-09-08 10:30:00',
                'orders_without_points' => 47,
            ],
            $notice['data']
        );
    }

    public function test_paused_default_campaign_clamps_negative_difference(): void {
        $campaigns = [ $this->campaign( 1, CampaignRepository::DEFAULT_PURCHASE_SLUG, Status::PAUSED, '2026-09-08 10:30:00' ) ];
        $notice    = $this->collect(
            $this->repository(
                [
                    'orders'           => 5,
                    'orders_with_earn' => 9,
                ]
            ),
            $campaigns
        )['default_campaign_paused'];

        $this->assertSame( 0, $notice['data']['orders_without_points'] );
    }

    public function test_no_jump_no_notice(): void {
        $this->assertArrayNotHasKey( 'points_jump', $this->collect( $this->repository() ) );
    }

    public function test_points_jump_with_an_edit_yesterday(): void {
        // 2026-09-15 02:14 Saigon = 2026-09-14 19:14 UTC — still "yesterday" in site time.
        $campaigns = [
            $this->campaign( 1, CampaignRepository::DEFAULT_PURCHASE_SLUG, Status::ACTIVE, '2026-09-14 19:14:00', 5 ),
            $this->campaign( 2, 'other', Status::ACTIVE, '2026-09-01 00:00:00', 5 ),
        ];
        $notice    = $this->collect( $this->repository( [ 'spike' => true ] ), $campaigns )['points_jump'];
        $data      = $notice['data'];

        $this->assertSame( 'affected', $notice['severity'] );
        $this->assertSame( self::YESTERDAY, $notice['fingerprint'] );
        $this->assertSame( self::YESTERDAY, $data['yesterday'] );
        $this->assertSame( 41200, $data['yesterday_points'] );
        $this->assertSame( 6100, $data['daily_average'] );
        $this->assertSame( 6.8, $data['multiple'] );
        $this->assertCount( 13, $data['daily'] );
        $this->assertSame(
            [
                'date'   => '2026-09-03',
                'issued' => 6100,
            ],
            $data['daily'][0]
        );
        $this->assertSame(
            [
                'date'   => '2026-09-15',
                'issued' => 41200,
            ],
            $data['daily'][12]
        );
        $this->assertSame( 3.0, $data['threshold']['multiple'] );
        $this->assertSame( 20, $data['threshold']['min_orders'] );
        $this->assertSame( 'orders://202609', $data['orders_admin_url'] );
        $this->assertSame(
            [
                [
                    'campaign_id'     => 1,
                    'name'            => 'Campaign 1',
                    'updated_at'      => '2026-09-14 19:14:00',
                    'updated_by_name' => 'User 5',
                    'orders_affected' => 214,
                    'points_issued'   => 41200,
                ],
            ],
            $data['what_changed']
        );
    }

    public function test_points_jump_without_an_edit_still_fires(): void {
        $campaigns = [ $this->campaign( 1, CampaignRepository::DEFAULT_PURCHASE_SLUG, Status::ACTIVE, '2026-09-01 00:00:00' ) ];
        $notice    = $this->collect( $this->repository( [ 'spike' => true ] ), $campaigns )['points_jump'];

        $this->assertSame( [], $notice['data']['what_changed'] );
    }

    public function test_points_jump_needs_orders(): void {
        $this->assertArrayNotHasKey(
            'points_jump',
            $this->collect(
                $this->repository(
                    [
                        'spike'            => true,
                        'yesterday_orders' => 3,
                    ]
                )
            )
        );
    }

    public function test_ordering_is_affected_then_waiting_then_progress_and_stable(): void {
        $campaigns = [ $this->campaign( 1, CampaignRepository::DEFAULT_PURCHASE_SLUG, Status::PAUSED, '2026-09-08 10:30:00' ) ];
        $keys      = array_column(
            $this->notices(
                $this->repository(
                    [
                        'negatives' => 2,
                        'spike'     => true,
                    ]
                ),
                $campaigns
            )->collect( $this->now() ),
            'key'
        );

        // Detector order is negative → paused → jump; severity sort must put
        // both affected notices first in that same relative order.
        $this->assertSame( [ 'default_campaign_paused', 'points_jump', 'negative_balance' ], $keys );
    }

    public function test_no_campaign_or_user_names_leak_beyond_data(): void {
        $notice = $this->collect( $this->repository( [ 'negatives' => 2 ] ) )['negative_balance'];

        $this->assertSame( [ 'key', 'severity', 'fingerprint', 'data' ], array_keys( $notice ) );
    }
}
