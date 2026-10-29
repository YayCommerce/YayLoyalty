<?php
namespace YayLoyalty\Tests\Integration\Repositories;

use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/** LotRepository's read of the points due by a date, which the launcher prints beside the soonest expiry. */
final class LotRepositoryTest extends IntegrationTestCase {

    private const USER_ID = 616161;

    public function test_the_points_due_by_the_soonest_date_leave_later_lots_and_stamp_nothing(): void {
        $soonest = Dates::offset( 10 * DAY_IN_SECONDS );
        $lot_ids = [
            $this->lot( 1, 100, $soonest ),
            $this->lot( 2, 250, $soonest ),
            $this->lot( 3, 400, Dates::offset( 40 * DAY_IN_SECONDS ) ),
        ];
        $lots    = new LotRepository();

        $this->assertSame( $soonest, $lots->earliest_open_expiry( self::USER_ID ) );
        $this->assertSame( 350, $lots->remaining_expiring_by( self::USER_ID, $soonest ) );
        $this->assertSame( [ null, null, null ], array_map( [ $this, 'reminded_at' ], $lot_ids ) );
    }

    /** Earns $points as order $order_id and dates the lot it opens; returns the lot id. */
    private function lot( int $order_id, int $points, string $expires_at ): int {
        global $wpdb;

        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, $order_id, $points ) );

        $lot_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM `' . esc_sql( Table::name( 'lots' ) ) . '`' ); // phpcs:ignore WordPress.DB -- the test reads its own scratch table.
        $wpdb->update( Table::name( 'lots' ), [ 'expires_at' => $expires_at ], [ 'id' => $lot_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test sets the column directly.

        return $lot_id;
    }

    private function reminded_at( int $lot_id ): ?string {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( 'SELECT reminded_at FROM `' . esc_sql( Table::name( 'lots' ) ) . '` WHERE id = %d', $lot_id ) ); // phpcs:ignore WordPress.DB -- the test reads its own scratch table.
    }
}
