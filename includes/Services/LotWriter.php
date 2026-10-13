<?php
namespace YayLoyalty\Services;

use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LotAllocationRepository;
use YayLoyalty\Repositories\LotRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one customer's lots, and customers.expires_at, in step with each ledger row. Constructed and
 * called only by LedgerService, inside the row's transaction under the customer lock, so its writes
 * commit or roll back with the row.
 * Invariants: per lot, remaining = points - SUM(allocations.points); per customer,
 * SUM(remaining of open lots) = max(balance, 0).
 */
final class LotWriter {

    /** @var LotRepository */
    private $lots;

    /** @var LotAllocationRepository */
    private $allocations;

    /** @var CustomerRepository */
    private $customers;

    public function __construct( LotRepository $lots, LotAllocationRepository $allocations, CustomerRepository $customers ) {
        $this->lots        = $lots;
        $this->allocations = $allocations;
        $this->customers   = $customers;
    }

    /**
     * @param LedgerEntry $row    The persisted row, with its id.
     * @param Customer    $before The locked customer as it was before $row.
     */
    public function record( LedgerEntry $row, Customer $before ): void {
        if ( $row->get_points() > 0 ) {
            $this->open_lot( $row, $before->get_balance() );
        } elseif ( $row->get_points() < 0 ) {
            $this->take_from_lots( $row, $before->get_balance() );
        }

        $this->sync_expires_at( $row, $before );
    }

    /** Re-dates customers.expires_at after $row, writing only when the date moved. */
    private function sync_expires_at( LedgerEntry $row, Customer $before ): void {
        $last_activity_at = LedgerType::is_activity( $row->get_type() ) ? $row->get_created_at() : $before->get_last_activity_at();
        $earliest_lot     = ExpiryRules::MODEL_BATCHES === ExpiryRules::model() ? $this->lots->earliest_open_expiry( $row->get_user_id() ) : null;
        $expires_at       = ExpiryRules::customer_expires_at( $row->get_balance_after(), $last_activity_at, $before->get_joined_at(), $earliest_lot );

        if ( $expires_at !== $before->get_expires_at() ) {
            $this->customers->set_expires_at( $row->get_user_id(), $expires_at );
        }
    }

    /**
     * One lot for a positive row. At a negative balance the row first pays the deficit, recorded as an
     * allocation against the row itself, and the lot keeps only the rest.
     */
    private function open_lot( LedgerEntry $row, int $balance_before ): void {
        $points = $row->get_points();
        $paid   = $balance_before < 0 ? min( -$balance_before, $points ) : 0;
        $lot_id = $this->lots->insert_lot( $row->get_user_id(), (int) $row->get_id(), $points, $points - $paid, ExpiryRules::lot_expires_at( $row->get_created_at() ), $row->get_created_at() );

        if ( $paid > 0 ) {
            $this->allocations->insert( $lot_id, (int) $row->get_id(), $paid, $row->get_created_at() );
        }
    }

    /**
     * Takes a negative row's points from open lots, oldest first. Only what the balance held comes out of
     * lots; anything beyond it is the deficit, which a later positive row pays.
     */
    private function take_from_lots( LedgerEntry $row, int $balance_before ): void {
        $left       = min( -$row->get_points(), max( $balance_before, 0 ) );
        $emptied_as = LedgerType::EXPIRE === $row->get_type() ? LotRepository::STATUS_EXPIRED : LotRepository::STATUS_CONSUMED;

        foreach ( $this->lots->open_lots_fifo( $row->get_user_id() ) as $lot ) {
            if ( $left <= 0 ) {
                break;
            }

            $take      = min( $left, $lot['remaining'] );
            $remaining = $lot['remaining'] - $take;
            $left     -= $take;

            $this->allocations->insert( $lot['id'], (int) $row->get_id(), $take, $row->get_created_at() );
            $this->lots->set_remaining( $lot['id'], $remaining, 0 === $remaining ? $emptied_as : LotRepository::STATUS_OPEN );
        }
    }
}
