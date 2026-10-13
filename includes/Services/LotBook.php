<?php
namespace YayLoyalty\Services;

use YayLoyalty\Engine\Expiry\LotRebuild;
use YayLoyalty\Enums\LotStatus;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps lots in step with the ledger, so that for every user SUM(open remaining) = max(0, balance).
 * Constructed only by LedgerService, and called only inside its transaction under the customer's
 * row lock: lots are a second view of the balance and must commit or roll back with it.
 */
final class LotBook {

    /** @var LotRepository */
    private $lots;

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( LotRepository $lots, LedgerRepository $ledger ) {
        $this->lots   = $lots;
        $this->ledger = $ledger;
    }

    /**
     * Applies one persisted ledger row. A positive row opens a lot for the part of it above zero. A
     * negative row takes the part of it the balance covered, from $source_ledger_id's lot first (a
     * clawback reverses its own earning), then oldest first.
     *
     * @param int $balance_before The locked balance before the row.
     */
    public function record( LedgerEntry $row, int $balance_before, ?int $source_ledger_id ): void {
        $points  = $row->get_points();
        $user_id = $row->get_user_id();

        if ( $points > 0 ) {
            $size = min( $points, max( 0, $balance_before + $points ) );

            if ( $size > 0 ) {
                $this->lots->open( (int) $row->get_id(), $user_id, $size, $size, $row->get_created_at(), null );
            }

            return;
        }

        $source = [];
        $rest   = [];

        foreach ( $this->lots->open_lots( $user_id, 'ASC' ) as $lot ) {
            if ( $lot['ledger_id'] === $source_ledger_id ) {
                $source[] = $lot;
            } else {
                $rest[] = $lot;
            }
        }

        $this->take( $row, min( -$points, max( 0, $balance_before ) ), array_merge( $source, $rest ), LotStatus::CONSUMED );
    }

    /**
     * Opens lots for a balance that predates them, rebuilt from the user's ledger history, unless the
     * user already has lots or nothing to cover. Runs before the next row is inserted, or from the job.
     *
     * @return int Lots opened.
     */
    public function backfill( int $user_id, int $balance ): int {
        if ( $balance <= 0 || $this->lots->has_any( $user_id ) ) {
            return 0;
        }

        return $this->rebuild( $user_id, $balance );
    }

    /**
     * History short of the balance means the balance and the ledger disagree. The oldest lot then
     * absorbs the gap, so the invariant holds and the gap expires first.
     *
     * @return int Lots opened.
     */
    public function rebuild( int $user_id, int $balance ): int {
        $lots      = LotRebuild::from_ledger( $this->ledger->positive_rows_newest_first( $user_id, $balance ), $balance );
        $shortfall = $balance - array_sum( array_column( $lots, 'remaining' ) );
        $oldest    = count( $lots ) - 1;

        if ( $shortfall > 0 ) {
            Log::warning( 'LotBook', sprintf( 'user %d: ledger history is %d short of a %d balance.', $user_id, $shortfall, $balance ) );

            if ( $oldest >= 0 ) {
                $lots[ $oldest ]['remaining'] += $shortfall;
                $lots[ $oldest ]['points']     = max( $lots[ $oldest ]['points'], $lots[ $oldest ]['remaining'] );
            }
        }

        foreach ( $lots as $lot ) {
            $this->lots->open( $lot['ledger_id'], $user_id, $lot['points'], $lot['remaining'], $lot['created_at'], null );
        }

        return count( $lots );
    }

    /**
     * Takes $need points from $open in order, each lot that empties getting $empty_status. Never
     * throws on a shortfall: a historical drift must not block a refund, so it is logged instead.
     *
     * @param array<int, array{id: int, remaining: int}> $open
     */
    private function take( LedgerEntry $row, int $need, array $open, string $empty_status ): void {
        foreach ( $open as $lot ) {
            if ( $need <= 0 ) {
                return;
            }

            $slice = min( $need, $lot['remaining'] );
            $this->lots->take( $lot['id'], $slice, (int) $row->get_id(), $empty_status, $row->get_created_at() );
            $need -= $slice;
        }

        if ( $need > 0 ) {
            Log::warning( 'LotBook', sprintf( 'user %d: ledger row %d left %d points with no open lot to take from.', $row->get_user_id(), (int) $row->get_id(), $need ) );
        }
    }
}
