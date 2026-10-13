<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Table;

/**
 * Every ledger row keeps the customer's lots in step: a positive row opens a lot, a negative one takes from
 * open lots oldest first, and a positive row arriving at a negative balance pays the deficit against itself.
 * Per lot, remaining = points - SUM(allocations); per customer, SUM(open remaining) = max(balance, 0).
 */
final class LotWriterTest extends IntegrationTestCase {

    private const USER_ID = 616161;

    public function test_earn_opens_a_lot(): void {
        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 1, 100, '2026-03-14 09:00:00' ) );

        $row  = $this->ledger_rows( self::USER_ID )[0];
        $lots = $this->lots( self::USER_ID );
        $this->assertCount( 1, $lots );
        $this->assertSame(
            [ '100', '100', 'open', $row['id'], '2026-03-14 09:00:00' ],
            [ $lots[0]['points'], $lots[0]['remaining'], $lots[0]['status'], $lots[0]['ledger_id'], $lots[0]['created_at'] ]
        );
    }

    public function test_negative_row_consumes_the_oldest_lot_first(): void {
        $service = $this->ledger_service();
        $service->earn( $this->earn_draft( self::USER_ID, 1, 100, '2026-01-01 00:00:00' ) );
        $service->earn( $this->earn_draft( self::USER_ID, 2, 50, '2026-02-01 00:00:00' ) );
        $deduct = $service->adjust( self::USER_ID, -120, 'test', 0 );

        $this->assertSame(
            [ [ '0', 'consumed' ], [ '30', 'open' ] ],
            array_map(
                static fn( array $lot ): array => [ $lot['remaining'], $lot['status'] ],
                $this->lots( self::USER_ID )
            )
        );
        $this->assertSame( [ '100', '20' ], array_column( $this->allocations_for( (int) $deduct->get_id() ), 'points' ) );
        $this->assert_invariant( self::USER_ID );
    }

    public function test_earn_pays_the_deficit_against_itself(): void {
        $service = $this->ledger_service();
        $service->adjust( self::USER_ID, -40, 'test', 0 );
        $service->earn( $this->earn_draft( self::USER_ID, 1, 100 ) );

        $earn_id = (int) $this->ledger_rows( self::USER_ID )[1]['id'];
        $lot     = $this->lots( self::USER_ID )[0];
        $this->assertSame( [ '100', '60', 'open' ], [ $lot['points'], $lot['remaining'], $lot['status'] ] );
        $this->assertSame(
            [ [ $lot['id'], (string) $earn_id, '40' ] ],
            array_map(
                static fn( array $allocation ): array => [ $allocation['lot_id'], $allocation['ledger_id'], $allocation['points'] ],
                $this->allocations_for( $earn_id )
            )
        );
        $this->assert_invariant( self::USER_ID );
    }

    public function test_invariant_holds_after_a_mixed_sequence(): void {
        $service = $this->ledger_service();
        $earn    = $service->earn( $this->earn_draft( self::USER_ID, 1, 100 ) )->get_entry();
        $service->earn( $this->earn_draft( self::USER_ID, 2, 50 ) );
        $refund = $service->refund_earn( $earn, 80, ReferenceType::REFUND, 9001 )->get_entry();
        $service->adjust( self::USER_ID, -100, 'test', 0 );      // Balance -30.
        $service->restore_refund( $refund, 9001 );               // +80 pays the 30, the lot keeps 50.
        $service->adjust( self::USER_ID, 30, 'test', 0, false ); // A correction of +30.

        $this->assertSame( 80, (int) $this->customer_row( self::USER_ID )['balance'] );
        $this->assert_invariant( self::USER_ID );
    }

    /** Both halves of the lot invariant, read straight from the tables. */
    private function assert_invariant( int $user_id ): void {
        $open = 0;

        foreach ( $this->lots( $user_id ) as $lot ) {
            $taken = array_sum( array_map( 'intval', array_column( $this->allocations_of_lot( (int) $lot['id'] ), 'points' ) ) );
            $this->assertSame( (int) $lot['points'] - $taken, (int) $lot['remaining'], 'lot ' . $lot['id'] . ': remaining = points - allocations' );

            if ( 'open' === $lot['status'] ) {
                $open += (int) $lot['remaining'];
            }
        }

        $this->assertSame( max( (int) $this->customer_row( $user_id )['balance'], 0 ), $open, 'open remaining = max(balance, 0)' );
    }

    /** @return array<int, array<string, string>> One customer's lots, oldest first. */
    private function lots( int $user_id ): array {
        return $this->rows( 'lots', 'user_id', $user_id );
    }

    /** @return array<int, array<string, string>> The allocations one ledger row made, in order. */
    private function allocations_for( int $ledger_id ): array {
        return $this->rows( 'lot_allocations', 'ledger_id', $ledger_id );
    }

    /** @return array<int, array<string, string>> */
    private function allocations_of_lot( int $lot_id ): array {
        return $this->rows( 'lot_allocations', 'lot_id', $lot_id );
    }

    /** @return array<int, array<string, string>> */
    private function rows( string $slug, string $column, int $value ): array {
        global $wpdb;

        return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB -- tests read the table directly, past any cache; the names are escaped literals.
            $wpdb->prepare(
                'SELECT * FROM `' . esc_sql( Table::name( $slug ) ) . '` WHERE `' . esc_sql( $column ) . '` = %d ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- escaped identifiers.
                $value
            ),
            ARRAY_A
        );
    }
}
