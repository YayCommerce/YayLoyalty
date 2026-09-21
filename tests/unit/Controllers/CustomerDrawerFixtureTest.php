<?php
namespace YayLoyalty\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Controllers\Fixtures\CustomerDrawer\CustomerDrawerFixture;

/** The paging/filter helpers every drawer fixture envelope goes through. */
final class CustomerDrawerFixtureTest extends TestCase {

    public function test_page_slices_by_page_and_per_page(): void {
        $rows = [ [ 'k' => 'a' ], [ 'k' => 'b' ], [ 'k' => 'c' ], [ 'k' => 'd' ], [ 'k' => 'e' ] ];

        $envelope = CustomerDrawerFixture::page(
            $rows,
            [
                'page'     => 2,
                'per_page' => 2,
            ]
        );

        $this->assertSame( [ [ 'k' => 'c' ], [ 'k' => 'd' ] ], $envelope['items'] );
        $this->assertSame( 5, $envelope['total'] );
        $this->assertSame( 2, $envelope['page'] );
        $this->assertSame( 2, $envelope['per_page'] );
    }

    public function test_with_ids_counts_down_and_never_overrides_row_values(): void {
        $rows = CustomerDrawerFixture::with_ids( [ [ 'currency' => 'EUR' ], [ 'x' => 1 ] ], [ 'currency' => 'USD' ] );

        $this->assertSame(
            [
                'id'       => 2,
                'currency' => 'EUR',
            ],
            $rows[0]
        );
        $this->assertSame(
            [
                'id'       => 1,
                'x'        => 1,
                'currency' => 'USD',
            ],
            $rows[1]
        );
    }

    public function test_date_window_is_inclusive_on_the_day(): void {
        $this->assertTrue( CustomerDrawerFixture::in_date_window( '2026-01-15 10:00:00', [ 'after' => '2026-01-15' ] ) );
        $this->assertTrue( CustomerDrawerFixture::in_date_window( '2026-01-15 10:00:00', [ 'before' => '2026-01-15' ] ) );
        $this->assertFalse( CustomerDrawerFixture::in_date_window( '2026-01-15 10:00:00', [ 'before' => '2026-01-14' ] ) );
        $this->assertTrue( CustomerDrawerFixture::in_date_window( '2026-01-15 10:00:00', [] ) );
    }

    public function test_search_needle_is_lower_cased_trimmed_and_empty_for_null(): void {
        $this->assertSame( 'goodwill', CustomerDrawerFixture::search_needle( [ 'search' => '  GoodWill ' ] ) );
        $this->assertSame( '', CustomerDrawerFixture::search_needle( [ 'search' => null ] ) );
        $this->assertSame( '', CustomerDrawerFixture::search_needle( [] ) );
    }
}
