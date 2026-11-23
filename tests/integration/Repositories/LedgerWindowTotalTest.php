<?php
namespace YayLoyalty\Tests\Integration\Repositories;

use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/**
 * LedgerRepository::window_total() dates each clawback by the earn row it reverses, staff_adjust_since()
 * leaves out refund restores, and sum_refunded_for() still claws back what it did before sharing the match rule.
 * Windows are relative to now, because the services write their rows at the current time.
 */
final class LedgerWindowTotalTest extends IntegrationTestCase {

    private const USER = 7;

    /** @var LedgerRepository */
    private $ledger;

    /** @var string The current window's start: six months back. */
    private $from;

    /** @var string The current window's end: now. */
    private $to;

    /** @var string Eight months back, inside the old window only. */
    private $old;

    protected function setUp(): void {
        parent::setUp();

        $this->ledger = new LedgerRepository();
        $this->to     = Dates::offset( 60 );
        $this->from   = Dates::add_months( Dates::now(), -6 );
        $this->old    = Dates::add_months( Dates::now(), -8 );
    }

    public function test_refund_of_an_old_earn_counts_in_the_earn_window(): void {
        $old_earn = $this->order_earn( 1001, 900, $this->old );
        $this->order_earn( 1002, 100, Dates::offset( -30 * DAY_IN_SECONDS ) );
        $this->ledger_service()->refund_earn( $old_earn, 900, ReferenceType::REFUND, 501 );

        $this->assertSame( 100, $this->ledger->window_total( self::USER, $this->from, $this->to ) );
        $this->assertSame( 0, $this->ledger->window_total( self::USER, $this->old_from(), $this->old_to() ) );
    }

    public function test_restore_adjust_follows_its_earn(): void {
        $old_earn = $this->order_earn( 1001, 900, $this->old );
        $this->order_earn( 1002, 100, Dates::offset( -30 * DAY_IN_SECONDS ) );
        $refund = $this->ledger_service()->refund_earn( $old_earn, 900, ReferenceType::REFUND, 501 )->get_entry();
        $this->ledger_service()->restore_refund( $refund, 501 );

        $this->assertSame( 100, $this->ledger->window_total( self::USER, $this->from, $this->to ) );
        $this->assertSame( 900, $this->ledger->window_total( self::USER, $this->old_from(), $this->old_to() ) );
    }

    public function test_each_trigger_of_an_order_takes_its_own_earn_date(): void {
        $campaign_x = $this->insert_campaign( 'Purchase X', CampaignTrigger::PURCHASE, Status::ACTIVE, 1 );
        $campaign_y = $this->insert_campaign( 'First order Y', CampaignTrigger::FIRST_ORDER, Status::ACTIVE, 2 );
        $x          = $this->order_earn( 1003, 300, $this->old, $campaign_x, CampaignTrigger::PURCHASE );
        $y          = $this->order_earn( 1003, 50, Dates::offset( -30 * DAY_IN_SECONDS ), $campaign_y, CampaignTrigger::FIRST_ORDER );
        $this->ledger_service()->refund_earn( $x, 300, ReferenceType::REFUND, 502 );
        $this->ledger_service()->refund_earn( $y, 50, ReferenceType::REFUND, 502 );

        $this->assertSame( 0, $this->ledger->window_total( self::USER, $this->from, $this->to ) );
        $this->assertSame( 0, $this->ledger->window_total( self::USER, $this->old_from(), $this->old_to() ) );
    }

    public function test_review_clawback_follows_its_earn(): void {
        $earn = $this->review_earn( 77, 20, $this->old );
        $this->ledger_service()->refund_earn( $earn, 20, ReferenceType::COMMENT, 77 );

        $this->assertSame( 0, $this->ledger->window_total( self::USER, $this->from, $this->to ) );
    }

    public function test_unmatched_clawback_counts_on_its_own_date(): void {
        $this->ledger->insert(
            new LedgerEntry( null, self::USER, -40, -40, LedgerType::REFUND_EARN, LedgerSource::ORDER, ReferenceType::REFUND, 503, null, null, 999999, null, null, Dates::offset( -30 * DAY_IN_SECONDS ), 'test:unmatched-clawback' )
        );

        $this->assertSame( -40, $this->ledger->window_total( self::USER, $this->from, $this->to ) );
    }

    public function test_staff_adjust_since_skips_refund_restores(): void {
        $earn   = $this->order_earn( 1004, 900, '' );
        $refund = $this->ledger_service()->refund_earn( $earn, 900, ReferenceType::REFUND, 504 )->get_entry();
        $this->backdate( (int) $this->ledger_service()->restore_refund( $refund, 504 )->get_entry()->get_id(), Dates::offset( -1 * DAY_IN_SECONDS ) );
        $this->backdate( (int) $this->ledger_service()->adjust( self::USER, -100, 'staff', 1 )->get_id(), Dates::offset( -3 * DAY_IN_SECONDS ) );
        $this->backdate( (int) $this->ledger_service()->adjust( self::USER, 30, 'staff', 1 )->get_id(), Dates::offset( -2 * DAY_IN_SECONDS ) );

        $this->assertSame( -70, $this->ledger->staff_adjust_since( self::USER, Dates::offset( -4 * DAY_IN_SECONDS ) ) );
        $this->assertSame( 30, $this->ledger->staff_adjust_since( self::USER, $this->created_at_of_adjust( -100 ) ) ); // Exclusive bound.
    }

    /** @group P-9 */
    public function test_counts_toward_tier_overrides_the_type_both_ways(): void {
        $this->order_earn( 1006, 100, '' );
        $this->manual_row( LedgerType::ADJUST, 500, false );
        $this->manual_row( LedgerType::CORRECTION, 200, true );
        $this->manual_row( LedgerType::CORRECTION, 50, null );

        $this->assertSame( 300, $this->ledger->window_total( self::USER, $this->from, $this->to ) );
        $this->assertSame( 200, $this->ledger->staff_adjust_since( self::USER, Dates::offset( -DAY_IN_SECONDS ) ) );
    }

    /** @group P-9 */
    public function test_except_refund_gives_the_total_before_every_row_of_that_refund(): void {
        $campaign_x = $this->insert_campaign( 'Purchase X', CampaignTrigger::PURCHASE, Status::ACTIVE, 1 );
        $campaign_y = $this->insert_campaign( 'First order Y', CampaignTrigger::FIRST_ORDER, Status::ACTIVE, 2 );
        $x          = $this->order_earn( 1007, 600, Dates::offset( -30 * DAY_IN_SECONDS ), $campaign_x, CampaignTrigger::PURCHASE );
        $y          = $this->order_earn( 1007, 300, Dates::offset( -30 * DAY_IN_SECONDS ), $campaign_y, CampaignTrigger::FIRST_ORDER );
        $this->ledger_service()->refund_earn( $x, 200, ReferenceType::REFUND, 505 );
        $this->ledger_service()->refund_earn( $y, 100, ReferenceType::REFUND, 505 );
        $this->ledger_service()->refund_earn( $y, 150, ReferenceType::REFUND, 507 );

        $this->assertSame( 550, $this->ledger->window_total( self::USER, $this->from, $this->to ) );
        $this->assertSame( 850, $this->ledger->window_total( self::USER, $this->from, $this->to, null, ReferenceType::REFUND, 505 ) ); // Refund 507 took the remaining 50 of its cumulative 150.
    }

    /** @group P-9 */
    public function test_created_by_ignores_rows_created_after_a_closed_period(): void {
        $old_earn = $this->order_earn( 1008, 900, $this->old );
        $this->ledger_service()->refund_earn( $old_earn, 400, ReferenceType::REFUND, 506 );

        $this->assertSame( 500, $this->ledger->window_total( self::USER, $this->old_from(), $this->old_to() ) );
        $this->assertSame( 900, $this->ledger->window_total( self::USER, $this->old_from(), $this->old_to(), $this->old_to() ) );
    }

    public function test_sum_refunded_for_is_unchanged(): void {
        $earn = $this->order_earn( 1005, 200, '' );
        $this->ledger_service()->refund_earn( $earn, 50, ReferenceType::REFUND, 601 );
        $second = $this->ledger_service()->refund_earn( $earn, 100, ReferenceType::REFUND, 602 )->get_entry();

        $this->assertSame( [ 200, -50, -50 ], $this->ledger_points( self::USER ) );
        $this->assertSame( 100, $this->ledger->sum_refunded_for( $earn ) );

        $this->ledger_service()->restore_refund( $second, 602 );

        $this->assertSame( 50, $this->ledger->sum_refunded_for( $earn, false ) );

        $review = $this->review_earn( 78, 20, '' );
        $this->ledger_service()->refund_earn( $review, 20, ReferenceType::COMMENT, 78 );

        $this->assertSame( 20, $this->ledger->sum_refunded_for( $review ) );
    }

    /** A manual row with an explicit tier-points choice, written as stored rather than through adjust(). */
    private function manual_row( string $type, int $points, ?bool $counts_toward_tier ): void {
        $this->ledger->insert(
            new LedgerEntry( null, self::USER, $points, 0, $type, LedgerSource::MANUAL, null, null, null, null, null, 'staff', 1, Dates::now(), null, null, null, null, $counts_toward_tier )
        );
    }

    /** The old window, one month either side of $this->old. */
    private function old_from(): string {
        return Dates::add_months( $this->old, -1 );
    }

    private function old_to(): string {
        return Dates::add_months( $this->old, 1 );
    }

    /** A purchase EARN row through LedgerService, backdated to $at ('' keeps now); one row per trigger by $qualifier. */
    private function order_earn( int $order_id, int $points, string $at, ?int $campaign_id = null, string $qualifier = '' ): LedgerEntry {
        $draft = $this->earn_draft( self::USER, $order_id, $points, '', $campaign_id );

        if ( '' !== $qualifier ) {
            $draft = new LedgerEntry( null, self::USER, $points, 0, LedgerType::EARN, LedgerSource::ORDER, ReferenceType::ORDER, $order_id, $campaign_id, null, $order_id, null, null, Dates::now(), IdempotencyKey::compose( LedgerType::EARN, self::USER, ReferenceType::ORDER, $order_id, $qualifier ) );
        }

        return $this->written( $this->ledger_service()->earn( $draft )->get_entry(), $at );
    }

    /** A review EARN row for one comment, backdated to $at ('' keeps now). */
    private function review_earn( int $comment_id, int $points, string $at ): LedgerEntry {
        $draft = new LedgerEntry( null, self::USER, $points, 0, LedgerType::EARN, LedgerSource::REVIEW, ReferenceType::COMMENT, $comment_id, null, null, null, null, null, Dates::now(), IdempotencyKey::compose( LedgerType::EARN, self::USER, ReferenceType::COMMENT, $comment_id, CampaignTrigger::REVIEW ) );

        return $this->written( $this->ledger_service()->earn( $draft )->get_entry(), $at );
    }

    /** The row as stored after an optional backdate, so later reversals copy what the table holds. */
    private function written( LedgerEntry $entry, string $at ): LedgerEntry {
        if ( '' === $at ) {
            return $entry;
        }

        $this->backdate( (int) $entry->get_id(), $at );

        return $this->ledger->find( (int) $entry->get_id() );
    }

    private function backdate( int $id, string $at ): void {
        global $wpdb;

        $wpdb->update( Table::name( 'ledger' ), [ 'created_at' => $at ], [ 'id' => $id ], [ '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB -- a test fixture moves a row in time.
    }

    private function created_at_of_adjust( int $points ): string {
        foreach ( $this->ledger_rows( self::USER ) as $row ) {
            if ( LedgerType::ADJUST === $row['type'] && $points === (int) $row['points'] ) {
                return (string) $row['created_at'];
            }
        }

        $this->fail( 'No ADJUST row of ' . $points );
    }
}
