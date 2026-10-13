<?php
namespace YayLoyalty\Tests\Unit\Engine\Expiry;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Expiry\LotRebuild;

/** Rebuilding lots from history: the newest earnings are what a balance is still made of. */
final class LotRebuildTest extends TestCase {

    public function test_newest_rows_cover_the_balance_and_the_oldest_covering_row_is_partial(): void {
        $rows = [
            [
                'id'         => 9,
                'points'     => 300,
                'created_at' => '2026-08-01 00:00:00',
            ],
            [
                'id'         => 5,
                'points'     => 500,
                'created_at' => '2026-06-01 00:00:00',
            ],
            [
                'id'         => 2,
                'points'     => 400,
                'created_at' => '2026-03-01 00:00:00',
            ],
        ];

        $this->assertSame(
            [
                [
                    'ledger_id'  => 9,
                    'points'     => 300,
                    'remaining'  => 300,
                    'created_at' => '2026-08-01 00:00:00',
                ],
                [
                    'ledger_id'  => 5,
                    'points'     => 500,
                    'remaining'  => 200,
                    'created_at' => '2026-06-01 00:00:00',
                ],
            ],
            LotRebuild::from_ledger( $rows, 500 )
        );
    }

    public function test_balance_above_history_covers_every_row(): void {
        $rows = [
            [
                'id'         => 1,
                'points'     => 100,
                'created_at' => '2026-01-01 00:00:00',
            ],
        ];

        $this->assertSame( 100, LotRebuild::from_ledger( $rows, 250 )[0]['remaining'] );
        $this->assertCount( 1, LotRebuild::from_ledger( $rows, 250 ) );
    }

    public function test_zero_or_negative_balance_gives_no_lots(): void {
        $rows = [
            [
                'id'         => 1,
                'points'     => 100,
                'created_at' => '2026-01-01 00:00:00',
            ],
        ];

        $this->assertSame( [], LotRebuild::from_ledger( $rows, 0 ) );
        $this->assertSame( [], LotRebuild::from_ledger( $rows, -40 ) );
    }
}
