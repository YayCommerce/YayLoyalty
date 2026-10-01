<?php
namespace YayLoyalty\Tests\Integration\Repositories;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** The ledger reads the award and reversal paths decide on: today's earnings per source, and net clawback per earn. */
final class LedgerEarnedTodayAndRefundedTest extends IntegrationTestCase {

    private const USER     = 51;
    private const ORDER    = 900;
    private const CAMPAIGN = 5;

    /** @var string|false */
    private $timezone;

    protected function setUp(): void {
        parent::setUp();

        $this->timezone = get_option( 'timezone_string' );
        update_option( 'timezone_string', 'Asia/Ho_Chi_Minh' ); // UTC+7, no DST.
    }

    protected function tearDown(): void {
        update_option( 'timezone_string', (string) $this->timezone );

        parent::tearDown();
    }

    public function test_points_earned_today_counts_one_source_on_the_site_day_of_the_event(): void {
        $this->row( LedgerType::EARN, 40, '2026-06-09 17:30:00' ); // 00:30 on the 10th, site time.
        $this->row( LedgerType::EARN, 500, '2026-06-09 16:30:00' ); // 23:30 on the 9th.
        $this->row( LedgerType::EARN, 70, '2026-06-10 16:59:59' ); // 23:59:59 on the 10th.
        $this->row( LedgerType::EARN, 900, '2026-06-10 12:00:00', [ 'source' => LedgerSource::REVIEW ] );
        $this->row( LedgerType::REFUND_EARN, -30, '2026-06-10 12:00:00' );
        $this->row( LedgerType::EARN, 800, '2026-06-10 12:00:00', [ 'user_id' => self::USER + 1 ] );

        $ledger = new LedgerRepository();

        $this->assertSame( 110, $ledger->points_earned_today( self::USER, '2026-06-10 03:00:00', LedgerSource::ORDER ) );
        $this->assertSame( 900, $ledger->points_earned_today( self::USER, '2026-06-10 03:00:00', LedgerSource::REVIEW ) );
        $this->assertSame( 0, $ledger->points_earned_today( null, '2026-06-10 03:00:00', LedgerSource::ORDER ) );
    }

    public function test_sum_refunded_for_an_order_earn_nets_restores_and_ignores_other_campaigns(): void {
        $earn = $this->row( LedgerType::EARN, 100, '2026-06-01 10:00:00' );
        $this->row( LedgerType::REFUND_EARN, -30, '2026-06-02 10:00:00', $this->refund_link( 11 ) );
        $this->row( LedgerType::REFUND_EARN, -20, '2026-06-03 10:00:00', $this->refund_link( 12 ) );
        $this->row( LedgerType::ADJUST, 30, '2026-06-04 10:00:00', $this->refund_link( 11 ) );
        $this->row( LedgerType::REFUND_EARN, -40, '2026-06-04 10:00:00', [ 'campaign_id' => self::CAMPAIGN + 1 ] );

        $this->assertSame( 20, ( new LedgerRepository() )->sum_refunded_for( $earn ) );
    }

    public function test_sum_refunded_for_a_review_earn_matches_its_comment(): void {
        $earn = $this->row( LedgerType::EARN, 15, '2026-06-01 10:00:00', $this->review_link( 77 ) );
        $this->row( LedgerType::REFUND_EARN, -15, '2026-06-02 10:00:00', $this->review_link( 77 ) );
        $this->row( LedgerType::REFUND_EARN, -9, '2026-06-02 10:00:00', $this->review_link( 78 ) );

        $this->assertSame( 15, ( new LedgerRepository() )->sum_refunded_for( $earn ) );
    }

    public function test_reads_find_earn_and_refund_rows_by_their_links(): void {
        $purchase = $this->row( LedgerType::EARN, 100, '2026-06-01 10:00:00' );
        $bonus    = $this->row( LedgerType::EARN, 20, '2026-06-01 10:00:00', [ 'campaign_id' => self::CAMPAIGN + 1 ] );
        $refund   = $this->row( LedgerType::REFUND_EARN, -10, '2026-06-02 10:00:00', $this->refund_link( 11 ) );
        $review   = $this->row( LedgerType::EARN, 15, '2026-06-01 10:00:00', $this->review_link( 77 ) );

        $ledger = new LedgerRepository();

        $this->assertSame( [ $purchase->get_id(), $bonus->get_id() ], $this->ids( $ledger->earn_rows_for_order( self::ORDER ) ) );
        $this->assertSame( [ $refund->get_id() ], $this->ids( $ledger->refund_rows_for_refund( 11 ) ) );
        $this->assertSame( $review->get_id(), $ledger->find_earn_for_reference( ReferenceType::COMMENT, 77 )->get_id() );
        $this->assertNull( $ledger->find_earn_for_reference( ReferenceType::COMMENT, 78 ) );
    }

    public function test_pending_delta_never_goes_below_zero(): void {
        $customers = new CustomerRepository();
        $customers->ensure_row( self::USER );

        $customers->apply_pending_delta( self::USER, 50 );
        $this->assertSame( 50, (int) $this->customer_row( self::USER )['pending_points'] );

        $customers->apply_pending_delta( self::USER, -80 );
        $this->assertSame( 0, (int) $this->customer_row( self::USER )['pending_points'] );
    }

    /**
     * Inserts an order-source row for USER on ORDER/CAMPAIGN; $overrides replaces any of those links.
     *
     * @param array<string, mixed> $overrides
     */
    private function row( string $type, int $points, string $created_at, array $overrides = [] ): LedgerEntry {
        $link = $overrides + [
            'user_id'        => self::USER,
            'source'         => LedgerSource::ORDER,
            'reference_type' => ReferenceType::ORDER,
            'reference_id'   => self::ORDER,
            'campaign_id'    => self::CAMPAIGN,
            'order_id'       => self::ORDER,
        ];

        $entry = new LedgerEntry(
            null,
            $link['user_id'],
            $points,
            0,
            $type,
            $link['source'],
            $link['reference_type'],
            $link['reference_id'],
            $link['campaign_id'],
            null,
            $link['order_id'],
            null,
            null,
            $created_at
        );

        return $entry->with_persisted( ( new LedgerRepository() )->insert( $entry ), 0 );
    }

    /** @return array<string, mixed> */
    private function refund_link( int $refund_id ): array {
        return [
            'reference_type' => ReferenceType::REFUND,
            'reference_id'   => $refund_id,
        ];
    }

    /** @return array<string, mixed> */
    private function review_link( int $comment_id ): array {
        return [
            'source'         => LedgerSource::REVIEW,
            'reference_type' => ReferenceType::COMMENT,
            'reference_id'   => $comment_id,
            'order_id'       => null,
        ];
    }

    /**
     * @param LedgerEntry[] $entries
     * @return int[]
     */
    private function ids( array $entries ): array {
        return array_map(
            static function ( LedgerEntry $entry ): int {
                return (int) $entry->get_id();
            },
            $entries
        );
    }
}
