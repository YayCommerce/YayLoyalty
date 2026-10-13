<?php
namespace YayLoyalty\Engine\Expiry;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a balance's lots from ledger history, for a balance that predates the lots table. Spending
 * is FIFO, so what is left of a balance is its newest earnings: walk positive rows newest first until
 * they cover it, the oldest covering row only in part. Pure, so the unit suite runs it.
 */
final class LotRebuild {

    /**
     * When the history sums to less than the balance, the lots end at the oldest row and cover less
     * than the balance; the caller reports the shortfall.
     *
     * @param array<int, array{id: int, points: int, created_at: string}> $rows_newest_first Positive ledger rows.
     * @return array<int, array{ledger_id: int, points: int, remaining: int, created_at: string}> Newest first.
     */
    public static function from_ledger( array $rows_newest_first, int $balance ): array {
        $lots  = [];
        $cover = $balance;

        foreach ( $rows_newest_first as $row ) {
            if ( $cover <= 0 ) {
                break;
            }

            $remaining = min( (int) $row['points'], $cover );
            $cover    -= $remaining;

            $lots[] = [
                'ledger_id'  => (int) $row['id'],
                'points'     => (int) $row['points'],
                'remaining'  => $remaining,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $lots;
    }
}
