<?php
namespace YayLoyalty\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of the ledger and the customer aggregates, EarningService's award included.
 * Enforced by convention: it is constructed in exactly five places, Engine\RestAPI::controllers(),
 * Database\InitYayLoyaltyCustomer::maybe_create(), Engine\Earning\EarningService::build(),
 * Engine\Earning\Reversal::build() and (once its body lands) Engine\Redemption\RedemptionService::build().
 * Name a sixth here before adding it. Manual rows enter through adjust() and record(). System rows
 * enter through earn(), refund_earn(), restore_refund() and redeem(), which add the idempotency-key
 * duplicate branch.
 */
final class LedgerService {

    /** Outcome::skipped reason: the earn row is already clawed back as far as it is owed. */
    public const REASON_NOTHING_TO_CLAW_BACK = 'nothing_to_claw_back';

    /** Outcome::skipped reason: the order was reversed in full, so a deleted refund gives nothing back. */
    public const REASON_ORDER_REVERSED = 'order_reversed';

    /** @var LedgerRepository */
    private $ledger;

    /** @var CustomerRepository */
    private $customers;

    public function __construct( LedgerRepository $ledger, CustomerRepository $customers ) {
        $this->ledger    = $ledger;
        $this->customers = $customers;
    }

    /**
     * Manual admin adjustment, positive or negative. Deliberately not
     * idempotent (NULL reference): an admin may grant the same bonus twice,
     * which is why the controller enforces a magnitude ceiling.
     *
     * @param bool $adjust_lifetime True writes an ADJUST row: lifetime_earned
     *             moves by the same signed amount as the balance, so a
     *             deduction can undo a mistaken grant's tier progress. False
     *             writes a CORRECTION row: balance only.
     *
     * @throws \InvalidArgumentException When points is zero or the type is bad.
     */
    public function adjust( int $user_id, int $points, string $note, int $actor_id, bool $adjust_lifetime = true ): LedgerEntry {
        if ( 0 === $points ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an adjustment of zero points is meaningless.' );
        }

        $draft = new LedgerEntry(
            null,
            $user_id,
            $points,
            0, // balance_after is computed inside the lock.
            $adjust_lifetime ? LedgerType::ADJUST : LedgerType::CORRECTION,
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
     * Commits one evaluated award, the ledger row and the customer aggregates,
     * in one transaction under the customer's row lock.
     *
     * Preconditions: $draft is an EARN row with a non-null idempotency_key
     * (Engine\Earning\IdempotencyKey::compose()), points > 0, and created_at
     * set by the caller. Nothing slow or externally fallible may run inside.
     *
     * Returns Outcome::awarded( persisted row ), with balance_after = locked
     * balance + points and the aggregates moved. Returns Outcome::duplicate(
     * existing row ) instead when the UNIQUE index rejected the insert
     * (QueryException::is_duplicate_key()). That is a normal outcome, not an
     * error, and it leaves every aggregate untouched. Any other failure
     * rethrows after Transaction::run() rolls back. The recorded event fires
     * after the outermost commit (announce()).
     *
     * @param callable|null $points_under_lock Runs under the lock, after the duplicate check; its answer
     *                                         replaces the draft's points, and 0 skips. EarningService
     *                                         passes the award filter here.
     * @throws \InvalidArgumentException When the draft is not an earn row or lacks a key.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails.
     */
    public function earn( LedgerEntry $draft, ?callable $points_under_lock = null, string $skip_reason = '' ): Outcome {
        $key = $draft->get_idempotency_key();

        if ( LedgerType::EARN !== $draft->get_type() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: earn() writes EARN rows only.' );
        }

        if ( null === $key || '' === $key ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an earn row needs an idempotency key.' );
        }

        if ( $draft->get_points() < 1 ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an earn row awards a positive number of points.' );
        }

        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        return $this->write_keyed( $draft, $points_under_lock, $skip_reason );
    }

    /**
     * Claws back an earn row up to $owed_total, cumulatively: the row written
     * is the difference between what is owed now and what earlier reversals of
     * the same earn already took, computed under the customer lock so two
     * refunds cannot both take the same slice. Keyed on the reversing
     * reference and the earn's trigger. The balance may go negative.
     *
     * @param int    $owed_total     Total points owed back for $earn so far (capped at its points).
     * @param string $reference_type ReferenceType::ORDER (cancel), REFUND or COMMENT.
     * @return Outcome awarded (a REFUND_EARN row), duplicate, or skipped( REASON_NOTHING_TO_CLAW_BACK ).
     */
    public function refund_earn( LedgerEntry $earn, int $owed_total, string $reference_type, int $reference_id ): Outcome {
        $draft = $this->reversal_draft( $earn, LedgerType::REFUND_EARN, 0, $reference_type, $reference_id, null );

        return $this->write_keyed(
            $draft,
            function () use ( $earn, $owed_total ): int {
                return -max( 0, min( $owed_total, $earn->get_points() ) - $this->ledger->sum_refunded_for( $earn ) );
            },
            self::REASON_NOTHING_TO_CLAW_BACK
        );
    }

    /**
     * Gives back what one clawback row took, when its refund is deleted: an
     * ADJUST row referencing the refund, which sum_refunded_for() nets out so
     * a later refund claws again from the right starting point. Nothing once
     * the order itself was reversed (cancelled, trashed, refunded outside
     * WooCommerce): that reversal is final, and restoring would hand back
     * points on an order that no longer earns.
     */
    public function restore_refund( LedgerEntry $refund_row, int $refund_id ): Outcome {
        $draft          = $this->reversal_draft( $refund_row, LedgerType::ADJUST, abs( $refund_row->get_points() ), ReferenceType::REFUND, $refund_id, sprintf( 'Refund #%d deleted', $refund_id ) );
        $order_full_key = $this->reversal_key( $refund_row, LedgerType::REFUND_EARN, ReferenceType::ORDER, (int) $refund_row->get_order_id() );

        return $this->write_keyed(
            $draft,
            function () use ( $draft, $order_full_key ): int {
                return null === $this->ledger->find_by_idempotency_key( $order_full_key ) ? $draft->get_points() : 0;
            },
            self::REASON_ORDER_REVERSED
        );
    }

    /** A system row undoing part of $source: same customer, source, campaign and order, keyed on the new reference and $source's trigger. */
    private function reversal_draft( LedgerEntry $source, string $type, int $points, string $reference_type, int $reference_id, ?string $note ): LedgerEntry {
        $user_id = $source->get_user_id();

        return new LedgerEntry(
            null,
            $user_id,
            $points,
            0,
            $type,
            $source->get_source(),
            $reference_type,
            $reference_id,
            $source->get_campaign_id(),
            null,
            $source->get_order_id(),
            $note,
            null,
            Dates::now(),
            $this->reversal_key( $source, $type, $reference_type, $reference_id )
        );
    }

    /** The key a reversal row of $type against the given reference carries: $source's customer and trigger. */
    private function reversal_key( LedgerEntry $source, string $type, string $reference_type, int $reference_id ): string {
        return IdempotencyKey::compose( $type, $source->get_user_id(), $reference_type, $reference_id, IdempotencyKey::qualifier_of( (string) $source->get_idempotency_key() ) );
    }

    /**
     * The idempotent write every system row goes through. Under the
     * customer's lock, a known key is Outcome::duplicate. Otherwise the row
     * is appended and Outcome::awarded carries it. Precondition: $draft has
     * an idempotency key and valid vocabulary.
     *
     * @param callable|null $points_under_lock Returns the row's signed points once the lock is held; 0 writes nothing.
     * @param string        $skip_reason       Outcome::skipped's reason when $points_under_lock returns 0.
     */
    private function write_keyed( LedgerEntry $draft, ?callable $points_under_lock = null, string $skip_reason = '' ): Outcome {
        $key = (string) $draft->get_idempotency_key();

        return $this->under_lock(
            $draft->get_user_id(),
            function ( Customer $customer ) use ( $draft, $key, $points_under_lock, $skip_reason ): Outcome {
                // Under the lock a concurrent write for this user has finished, so this sees its row.
                $existing = $this->ledger->find_by_idempotency_key( $key );

                if ( null !== $existing ) {
                    return Outcome::duplicate( $existing );
                }

                if ( null !== $points_under_lock ) {
                    $points = (int) $points_under_lock();

                    if ( 0 === $points ) {
                        return Outcome::skipped( $skip_reason );
                    }

                    $draft = $draft->with_points( $points );
                }

                $persisted = $this->append( $draft, $customer, true );

                return null === $persisted ? Outcome::duplicate( $this->existing_for( $key ) ) : Outcome::awarded( $persisted );
            }
        );
    }

    /**
     * Runs $write in one transaction holding the customer's row lock.
     *
     * @param callable(Customer): mixed $write
     * @return mixed What $write returned.
     */
    private function under_lock( int $user_id, callable $write ) {
        return Transaction::run(
            function () use ( $user_id, $write ) {
                // Materialise before locking: a missing row gap-locks and two first-touch writers both insert.
                $this->customers->ensure_row( $user_id );

                return $write( $this->customers->lock_and_get( $user_id ) );
            }
        );
    }

    /**
     * Inserts $draft at the locked balance, moves the aggregates and
     * announces the row. Must run under_lock(), so balance_after chains.
     *
     * @param bool $is_keyed True turns a UNIQUE-key collision into null instead of an exception.
     */
    private function append( LedgerEntry $draft, Customer $customer, bool $is_keyed ): ?LedgerEntry {
        $balance_after = $customer->get_balance() + $draft->get_points();
        $to_insert     = $draft->with_persisted( 0, $balance_after );
        $insert_id     = $is_keyed ? $this->ledger->insert_unless_duplicate( $to_insert ) : $this->ledger->insert( $to_insert );

        if ( null === $insert_id ) {
            return null;
        }

        $this->apply_aggregates( $draft );

        $persisted = $to_insert->with_persisted( $insert_id, $balance_after );

        $this->announce( $persisted );

        return $persisted;
    }

    /**
     * The row the UNIQUE index rejected this write for. Separate from the
     * pre-check because here the row must exist: the ledger is append-only
     * and the insert just failed against it, so a miss means the index and
     * the table disagree and no outcome would be truthful.
     *
     * @throws \RuntimeException When the key that collided matches no row.
     */
    private function existing_for( string $key ): LedgerEntry {
        $existing = $this->ledger->find_by_idempotency_key( $key );

        if ( null === $existing ) {
            throw new \RuntimeException(
                sprintf( 'YayLoyalty: idempotency key "%s" collided but matches no ledger row.', $key )
            );
        }

        return $existing;
    }

    /**
     * Commits one quoted redemption: the REDEEM row and the customer
     * aggregates, in one transaction under the customer's row lock. The
     * redemption commit (Engine\Redemption\CommitWriter) wraps this in its
     * own Transaction::run(), so the row here and the redemptions row commit
     * or roll back together.
     *
     * Preconditions: $draft is a REDEEM row with points < 0, a non-null
     * idempotency_key (IdempotencyKey::compose( REDEEM, user, REDEMPTION,
     * redemption id, reward type )), redemption_id and order_id set,
     * created_at set by the caller. Nothing slow or externally fallible may
     * run inside.
     *
     * Postconditions: exactly one of — Outcome::redeemed( persisted row ) with
     * balance_after = locked balance + points (≥ 0) and lifetime_redeemed
     * moved; Outcome::skipped( Rewards\Result::REASON_INSUFFICIENT_BALANCE )
     * and nothing written when the locked balance + points would go below
     * zero — a redemption hard-refuses, unlike a clawback; or
     * Outcome::duplicate( existing row ) when the UNIQUE index rejected the
     * insert. Any other failure rethrows and the transaction has rolled back.
     * The recorded event fires after the outermost commit (announce()).
     *
     * @throws \InvalidArgumentException When the draft is not a negative redeem row or lacks a key.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails.
     */
    public function redeem( LedgerEntry $draft ): Outcome { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- scaffold stub.
        // TODO(redemption): mirror earn() — assert REDEEM, points < 0, key; Transaction::run: ensure_row, lock_and_get, if balance + points < 0 → Outcome::skipped( insufficient_balance ), pre-check, insert_unless_duplicate → Outcome::duplicate, apply_aggregates, announce(); return Outcome::redeemed.
        throw new \RuntimeException( 'Not implemented' );
    }

    /**
     * The manual write path (no idempotency key, so no duplicate branch).
     *
     * @throws \InvalidArgumentException When the draft's vocabulary is invalid.
     * @throws \YayLoyalty\Database\QueryException When a write fails.
     */
    private function record( LedgerEntry $draft ): LedgerEntry {
        // No enums in PHP 7.4: without this an invalid type surfaces as a DB error naming a column.
        LedgerType::assert( $draft->get_type() );
        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        return $this->under_lock(
            $draft->get_user_id(),
            function ( Customer $customer ) use ( $draft ): LedgerEntry {
                return $this->append( $draft, $customer, false );
            }
        );
    }

    /**
     * Updates the cached aggregates for one ledger row, gated only on the
     * row's type (`LedgerType::affects_lifetime_earned()` and siblings).
     */
    private function apply_aggregates( LedgerEntry $entry ): void {
        $type   = $entry->get_type();
        $points = $entry->get_points();

        $lifetime_earned_delta   = 0;
        $lifetime_redeemed_delta = 0;

        if ( LedgerType::affects_lifetime_earned( $type ) ) {
            $lifetime_earned_delta = $points;
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
            null // Expiry needs the lots table: designed, not built yet.
        );
    }

    /**
     * Fires the recorded event after the outermost commit, never inside the
     * transaction: a subscriber on another connection cannot see uncommitted
     * rows, its cache/HTTP writes cannot be rolled back, and it would hold
     * the row lock. Dropped when the write rolls back.
     */
    private function announce( LedgerEntry $entry ): void {
        Transaction::after_commit(
            static function () use ( $entry ): void {
                /**
                 * Fires after a ledger row is committed.
                 *
                 * @param LedgerEntry $entry
                 */
                do_action( 'yayloyalty_ledger_recorded', $entry );
            }
        );
    }
}
