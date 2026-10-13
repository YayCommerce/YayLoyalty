<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Table;

/** Every LedgerService write keeps lots in step: SUM(open remaining) = max(0, balance). */
final class LedgerServiceLotsTest extends IntegrationTestCase {

    private const USER_ID = 616161;

    public function test_earn_opens_a_lot_the_size_of_the_row(): void {
        $earn = $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9001, 500 ) )->get_entry();

        $lots = $this->lots( self::USER_ID );
        $this->assertCount( 1, $lots );
        $this->assertSame( [ $earn->get_id(), 500, 500, 'open' ], [ (int) $lots[0]['ledger_id'], (int) $lots[0]['points'], (int) $lots[0]['remaining'], $lots[0]['status'] ] );
        $this->assert_invariant( self::USER_ID );
    }

    public function test_negative_adjust_takes_oldest_first_and_writes_allocations(): void {
        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9001, 400, '2026-03-01 00:00:00' ) );
        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9002, 300, '2026-06-01 00:00:00' ) );
        $deduct = $this->ledger_service()->adjust( self::USER_ID, -500, 'test', 1 );

        $lots = $this->lots( self::USER_ID );
        $this->assertSame( [ 0, 200 ], array_map( 'intval', array_column( $lots, 'remaining' ) ) );
        $this->assertSame( [ 'consumed', 'open' ], array_column( $lots, 'status' ) );
        $this->assertSame( [ 400, 100 ], $this->allocated_points( (int) $deduct->get_id() ) );
        $this->assert_invariant( self::USER_ID );
    }

    public function test_refund_earn_takes_from_its_own_lot_first(): void {
        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9001, 400, '2026-03-01 00:00:00' ) );
        $second = $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9002, 300, '2026-06-01 00:00:00' ) )->get_entry();
        $this->ledger_service()->refund_earn( $second, 300, ReferenceType::ORDER, 9002 );

        $lots = $this->lots( self::USER_ID );
        $this->assertSame( [ 400, 0 ], array_map( 'intval', array_column( $lots, 'remaining' ) ) );
        $this->assert_invariant( self::USER_ID );
    }

    public function test_positive_row_while_negative_opens_only_the_part_above_zero(): void {
        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9001, 100 ) );
        $this->ledger_service()->adjust( self::USER_ID, -300, 'test', 1, false ); // Balance −200.
        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9002, 500 ) );

        $open = array_values(
            array_filter(
                $this->lots( self::USER_ID ),
                static function ( $lot ) {
                    return 'open' === $lot['status'];
                }
            )
        );
        $this->assertCount( 1, $open );
        $this->assertSame( 300, (int) $open[0]['points'] );
        $this->assertSame( 300, (int) $open[0]['remaining'] );
        $this->assert_invariant( self::USER_ID );
    }

    public function test_rolled_back_write_leaves_no_lot(): void {
        try {
            Transaction::run(
                function () {
                    $this->ledger_service()->adjust( self::USER_ID, 250, 'test', 1 );
                    throw new \RuntimeException( 'roll back' );
                }
            );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'roll back', $e->getMessage() );
        }

        $this->assertSame( [], $this->lots( self::USER_ID ) );
    }

    private function assert_invariant( int $user_id ): void {
        global $wpdb;

        $open_sum = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
            $wpdb->prepare( 'SELECT COALESCE(SUM(remaining), 0) FROM `' . esc_sql( Table::name( 'lots' ) ) . "` WHERE user_id = %d AND status = 'open'", $user_id )
        );

        $this->assertSame( max( 0, (int) $this->customer_row( $user_id )['balance'] ), $open_sum );
    }

    /** @return array<int, array<string, mixed>> Every lot for the user, oldest first. */
    private function lots( int $user_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( Table::name( 'lots' ) ) . '` WHERE user_id = %d ORDER BY id', $user_id ),
            ARRAY_A
        );
    }

    /** @return int[] Points each allocation of one ledger row took, oldest first. */
    private function allocated_points( int $ledger_id ): array {
        global $wpdb;

        return array_map(
            'intval',
            (array) $wpdb->get_col( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
                $wpdb->prepare( 'SELECT points FROM `' . esc_sql( Table::name( 'lot_allocations' ) ) . '` WHERE ledger_id = %d ORDER BY id', $ledger_id )
            )
        );
    }
}
