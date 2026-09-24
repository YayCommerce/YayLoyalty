<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Database\QueryException;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/**
 * The award writer against a real server: that the UNIQUE index — not the
 * pre-check, not a caller's discipline — is what stops one order and trigger
 * paying twice. These are the invariants the unit suite can only describe.
 */
final class LedgerGuardTest extends IntegrationTestCase {

    private const USER  = 4242;
    private const ORDER = 1234;

    public function test_an_award_writes_one_row_and_moves_the_aggregates(): void {
        $outcome = $this->ledger_service()->earn( $this->earn_draft( 150 ) );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertCount( 1, $this->ledger_rows( self::USER ) );

        $customer = $this->customer_row( self::USER );

        $this->assertSame( 150, (int) $customer['balance'] );
        $this->assertSame( 150, (int) $customer['lifetime_earned'] );
        $this->assertSame( 150, $outcome->get_entry()->get_balance_after() );
    }

    public function test_a_second_award_for_one_order_and_trigger_is_a_duplicate(): void {
        $service = $this->ledger_service();

        $first  = $service->earn( $this->earn_draft( 150 ) );
        $second = $service->earn( $this->earn_draft( 150 ) );

        $this->assertSame( Outcome::AWARDED, $first->get_status() );
        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( $first->get_entry()->get_id(), $second->get_entry()->get_id() );
        $this->assertCount( 1, $this->ledger_rows( self::USER ) );
    }

    /**
     * The index is the guard, the pre-check only an optimisation. Written
     * against the repository because that is the layer with no pre-check in
     * it: the insert is simply attempted, and the second one comes back as
     * "already there" because MySQL refused it, not because anything looked
     * first.
     */
    public function test_the_index_alone_refuses_the_second_row(): void {
        $repository = new LedgerRepository();
        $draft      = $this->earn_draft( 150 );

        $this->assertIsInt( $repository->insert_unless_duplicate( $draft ) );
        $this->assertNull( $repository->insert_unless_duplicate( $draft ) );
        $this->assertCount( 1, $this->ledger_rows( self::USER ) );
    }

    /** A failure that is not a collision must still reach the caller. */
    public function test_a_failure_that_is_not_a_duplicate_still_throws(): void {
        $repository = new LedgerRepository();

        // type is varchar(20); a longer one fails strict MySQL on 1406, not 1062.
        $overlong = new LedgerEntry(
            null,
            self::USER,
            150,
            150,
            str_repeat( 'x', 64 ),
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            self::ORDER,
            null,
            null,
            self::ORDER,
            null,
            null,
            Dates::now(),
            'not-a-key'
        );

        $this->expectException( QueryException::class );

        $repository->insert_unless_duplicate( $overlong );
    }

    public function test_a_collision_the_precheck_missed(): void {
        $this->markTestIncomplete(
            'earn() pre-checks under the customer row lock, so on one connection the index can never be the thing that rejects it. '
            . 'Reaching that branch needs a second connection inserting the same key between this one\'s pre-check and its insert, '
            . 'which the harness cannot interleave yet. The mechanism it depends on is covered by test_the_index_alone_refuses_the_second_row.'
        );
    }

    public function test_a_duplicate_moves_no_aggregate(): void {
        $service = $this->ledger_service();

        $service->earn( $this->earn_draft( 150 ) );
        $before = $this->customer_row( self::USER );

        $service->earn( $this->earn_draft( 150 ) );
        $after = $this->customer_row( self::USER );

        $this->assertSame( (int) $before['balance'], (int) $after['balance'] );
        $this->assertSame( (int) $before['lifetime_earned'], (int) $after['lifetime_earned'] );
        $this->assertSame( $before['last_activity_at'], $after['last_activity_at'] );
    }

    public function test_balance_after_is_an_unbroken_chain(): void {
        $service = $this->ledger_service();

        $service->earn( $this->earn_draft( 150, 1 ) );
        $service->earn( $this->earn_draft( 25, 2 ) );
        $service->earn( $this->earn_draft( 300, 3 ) );

        $previous = 0;

        foreach ( $this->ledger_rows( self::USER ) as $row ) {
            $this->assertSame(
                $previous + (int) $row['points'],
                (int) $row['balance_after'],
                sprintf( 'Row %d broke the chain.', (int) $row['id'] )
            );

            $previous = (int) $row['balance_after'];
        }

        $this->assertSame( 475, (int) $this->customer_row( self::USER )['balance'] );
    }

    /** A NULL key cannot collide, which is what keeps an admin free to grant the same bonus twice. */
    public function test_manual_adjustments_stay_non_idempotent(): void {
        $service = $this->ledger_service();

        $service->adjust( self::USER, 50, 'Goodwill', 1 );
        $service->adjust( self::USER, 50, 'Goodwill', 1 );

        $rows = $this->ledger_rows( self::USER );

        $this->assertCount( 2, $rows );
        $this->assertNull( $rows[0]['idempotency_key'] );
        $this->assertSame( 100, (int) $this->customer_row( self::USER )['balance'] );
    }

    /** Flag on: an ADJUST row, lifetime_earned follows the balance both ways. Off: a CORRECTION row, lifetime_earned stays put. */
    public function test_adjust_lifetime_moves_lifetime_earned_by_the_signed_amount(): void {
        $service = $this->ledger_service();

        $grant = $service->adjust( self::USER, 500, 'Grant', 1, true );
        $undo  = $service->adjust( self::USER, -200, 'Undo part of the grant', 1, true );
        $this->assertSame( 300, (int) $this->customer_row( self::USER )['lifetime_earned'] );
        $this->assertSame( LedgerType::ADJUST, $grant->get_type() );
        $this->assertSame( LedgerType::ADJUST, $undo->get_type() );

        $balance_only     = $service->adjust( self::USER, 100, 'Balance-only grant', 1, false );
        $balance_only_neg = $service->adjust( self::USER, -50, 'Balance-only deduction', 1, false );
        $this->assertSame( LedgerType::CORRECTION, $balance_only->get_type() );
        $this->assertSame( LedgerType::CORRECTION, $balance_only_neg->get_type() );

        $row = $this->customer_row( self::USER );
        $this->assertSame( 300, (int) $row['lifetime_earned'] );
        $this->assertSame( 350, (int) $row['balance'] );
    }

    public function test_the_recorded_hook_fires_for_an_award_and_not_for_a_duplicate(): void {
        $fired = 0;

        $listener = static function () use ( &$fired ): void {
            ++$fired;
        };

        add_action( 'yayloyalty_ledger_recorded', $listener );

        $service = $this->ledger_service();
        $service->earn( $this->earn_draft( 150 ) );
        $service->earn( $this->earn_draft( 150 ) );

        remove_action( 'yayloyalty_ledger_recorded', $listener );

        $this->assertSame( 1, $fired );
    }

    public function test_an_earn_row_without_a_key_is_refused(): void {
        $draft = new LedgerEntry(
            null,
            self::USER,
            150,
            0,
            LedgerType::EARN,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            self::ORDER,
            null,
            null,
            self::ORDER,
            null,
            null,
            Dates::now()
        );

        $this->expectException( \InvalidArgumentException::class );

        $this->ledger_service()->earn( $draft );
    }

    private function earn_draft( int $points, ?int $order_id = null ): LedgerEntry {
        $order_id = $order_id ?? self::ORDER;

        return new LedgerEntry(
            null,
            self::USER,
            $points,
            0,
            LedgerType::EARN,
            LedgerSource::ORDER,
            ReferenceType::ORDER,
            $order_id,
            null,
            null,
            $order_id,
            null,
            null,
            Dates::now(),
            IdempotencyKey::compose( LedgerType::EARN, self::USER, ReferenceType::ORDER, $order_id, CampaignTrigger::PURCHASE )
        );
    }
}
