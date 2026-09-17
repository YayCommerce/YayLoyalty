<?php
namespace YayLoyalty\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of the ledger and the customer aggregates. Enforced by
 * convention: constructed in exactly two places, Engine\RestAPI::controllers()
 * and Database\InitYayLoyaltyCustomer::maybe_create(). Name a third here
 * before adding it. Every movement type enters through record().
 */
final class LedgerService {

    /** @var LedgerRepository */
    private $ledger;

    /** @var CustomerRepository */
    private $customers;

    /** @var LedgerEntry[] Events collected in-transaction, dispatched after commit. */
    private $pending_events = [];

    public function __construct( LedgerRepository $ledger, CustomerRepository $customers ) {
        $this->ledger    = $ledger;
        $this->customers = $customers;
    }

    /**
     * Manual admin adjustment, positive or negative. Deliberately not
     * idempotent (NULL reference): an admin may grant the same bonus twice,
     * which is why the controller enforces a magnitude ceiling.
     *
     * @throws \InvalidArgumentException When points is zero or the type is bad.
     */
    public function adjust( int $user_id, int $points, string $note, int $actor_id ): LedgerEntry {
        if ( 0 === $points ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an adjustment of zero points is meaningless.' );
        }

        $draft = new LedgerEntry(
            null,
            $user_id,
            $points,
            0, // balance_after is computed inside the lock.
            LedgerType::ADJUST,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            $note,
            $actor_id > 0 ? $actor_id : null,
            Dates::now()
        );

        return $this->record( $draft );
    }

    /**
     * The single write path.
     *
     * @throws \InvalidArgumentException When the draft's vocabulary is invalid.
     * @throws \YayLoyalty\Database\QueryException When a write fails.
     */
    private function record( LedgerEntry $draft ): LedgerEntry {
        // No enums in PHP 7.4: without this an invalid type surfaces as a DB error naming a column.
        LedgerType::assert( $draft->get_type() );
        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        $entry = Transaction::run(
            function () use ( $draft ) {
                $user_id = $draft->get_user_id();

                // Materialise before locking: a missing row gap-locks and two first-touch writers both insert.
                $this->customers->ensure_row( $user_id );

                $customer = $this->customers->lock_and_get( $user_id );

                // Idempotency inside the lock. A SELECT, not INSERT IGNORE: IGNORE swallows real errors too.
                $existing = $this->ledger->find_by_reference(
                    $user_id,
                    $draft->get_source(),
                    $draft->get_reference_type(),
                    $draft->get_reference_id()
                );

                if ( null !== $existing ) {
                    return $existing;
                }

                $balance_after = $customer->get_balance() + $draft->get_points();

                $to_insert = $draft->with_persisted( 0, $balance_after );
                $insert_id = $this->ledger->insert( $to_insert );

                $this->apply_aggregates( $draft );

                $persisted = $to_insert->with_persisted( $insert_id, $balance_after );

                $this->pending_events[] = $persisted;

                return $persisted;
            }
        );

        $this->dispatch_events();

        return $entry;
    }

    /** Updates the cached aggregates for one ledger row. */
    private function apply_aggregates( LedgerEntry $entry ): void {
        $type   = $entry->get_type();
        $points = $entry->get_points();

        $lifetime_earned_delta   = 0;
        $lifetime_redeemed_delta = 0;

        if ( LedgerType::affects_lifetime_earned( $type ) ) {
            // A negative ADJUST neither inflates nor reduces lifetime_earned.
            if ( LedgerType::ADJUST !== $type || $points > 0 ) {
                $lifetime_earned_delta = $points;
            }
        }

        if ( LedgerType::affects_lifetime_redeemed( $type ) ) {
            // lifetime_redeemed is unsigned, so a redeem (negative points) increases it.
            $lifetime_redeemed_delta = -$points;
        }

        $activity_at = LedgerType::is_activity( $type ) ? $entry->get_created_at() : null;

        $this->customers->apply_delta(
            $entry->get_user_id(),
            $points,
            $lifetime_earned_delta,
            $lifetime_redeemed_delta,
            $activity_at,
            null // Expiry policy is a later Pro task (data-model.md `yayloyalty_lots`).
        );
    }

    /**
     * Fires collected events after commit, never inside the transaction: a
     * subscriber on another connection cannot see uncommitted rows, its
     * cache/HTTP writes cannot be rolled back, and it would hold the row lock.
     */
    private function dispatch_events(): void {
        $events               = $this->pending_events;
        $this->pending_events = [];

        foreach ( $events as $entry ) {
            /**
             * Fires after a ledger row is committed.
             *
             * @param LedgerEntry $entry
             */
            do_action( 'yayloyalty_ledger_recorded', $entry );
        }
    }
}
