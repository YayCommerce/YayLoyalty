<?php
namespace YayLoyalty\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Engine\Rewards\Result as RedeemResult;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of the ledger and the customer aggregates, EarningService's award included.
 * Enforced by convention: it is constructed in exactly five places, Engine\RestAPI::controllers(),
 * Engine\Earning\EarningService::build(), Engine\Earning\Reversal::build(),
 * Engine\Expiry\ExpiryRunner::run() and Engine\Redemption\RedemptionService::build().
 * Name a sixth here before adding it.
 * Manual rows enter through adjust() and record(). System rows enter through earn(),
 * refund_earn(), restore_refund(), expire() and redeem(), which add the idempotency-key duplicate branch.
 * Lock order: the customer row, then, for an earn row, its campaign row shared, which
 * CampaignRestController::delete_item() locks exclusively before deleting.
 * It also keeps `lots` and `lot_allocations` in step with every row, through LotService, which
 * nothing else constructs.
 */
final class LedgerService {

    /** Outcome::skipped reason: the earn row is already clawed back as far as it is owed. */
    public const REASON_NOTHING_TO_CLAW_BACK = 'nothing_to_claw_back';

    /** Outcome::skipped reason: the order was reversed in full, so a deleted refund gives nothing back. */
    public const REASON_ORDER_REVERSED = 'order_reversed';

    /** Outcome::skipped reason: the earn row's campaign was deleted before the award took its lock. */
    public const REASON_CAMPAIGN_DELETED = 'campaign_deleted';

    /** Outcome::skipped reason: under the lock the customer is no longer due to expire (expiry off, or activity moved the date). */
    public const REASON_NOT_DUE = 'not_due';

    /** Outcome::skipped reason: under the lock another customer still holds live points from the order (it was reassigned after it earned), so an order earn for its new customer is not written. */
    public const REASON_ORDER_HAS_POINTS = 'order_has_points';

    /** @var LedgerRepository */
    private $ledger;

    /** @var CustomerRepository */
    private $customers;

    /** @var CampaignRepository */
    private $campaigns;

    /** @var LotService */
    private $lots;

    public function __construct( LedgerRepository $ledger, CustomerRepository $customers, CampaignRepository $campaigns, ?LotService $lots = null ) {
        $this->ledger    = $ledger;
        $this->customers = $customers;
        $this->campaigns = $campaigns;
        $this->lots      = $lots ?? new LotService( new LotRepository(), $ledger );
    }

    /**
     * Manual admin adjustment, positive or negative. Deliberately not
     * idempotent (NULL reference): an admin may grant the same bonus twice.
     *
     * @param bool $adjust_lifetime True writes an ADJUST row: lifetime_earned
     *             moves by the same signed amount as the balance, so a
     *             deduction can undo a mistaken grant's lifetime total. False
     *             writes a CORRECTION row: balance only.
     * @param string|null $customer_note Shown to the customer in place of "Adjustment by {store}"; blank stores NULL.
     *
     * @throws \InvalidArgumentException When points is zero or the type is bad.
     */
    public function adjust( int $user_id, int $points, string $note, int $actor_id, bool $adjust_lifetime = true, ?string $customer_note = null ): LedgerEntry {
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
            Dates::now(),
            null,
            null === $customer_note || '' === trim( $customer_note ) ? null : trim( $customer_note )
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
     * Opens lots for a balance that predates them, under the customer lock. No ledger row and no
     * aggregate moves. A no-op when the user already has lots or no positive balance.
     *
     * @return int Lots opened.
     */
    public function backfill_lots( int $user_id ): int {
        return (int) $this->under_lock(
            $user_id,
            function ( Customer $customer ): int {
                return $this->lots->backfill( $customer->get_user_id(), $customer->get_balance() );
            }
        );
    }

    /**
     * Claws back an earn row up to $owed_total, cumulatively: the row written
     * is the difference between what is owed now and what earlier reversals of
     * the same earn already took, computed under the customer lock so two
     * refunds cannot both take the same slice. Points that already expired
     * out of the earn's lot count as taken, so a refund never charges for
     * them twice. Keyed on the reversing reference and the earn's trigger.
     * The balance may go negative.
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
                $taken = $this->ledger->sum_refunded_for( $earn ) + $this->lots->expired_from( (int) $earn->get_id() );

                return -max( 0, min( $owed_total, $earn->get_points() ) - $taken );
            },
            self::REASON_NOTHING_TO_CLAW_BACK,
            $earn->get_id()
        );
    }

    /**
     * Writes one EXPIRE row for what is due: in inactivity mode the whole balance, every open lot
     * `expired`; in batch mode the lots past their date, never more than the balance. Re-checked under
     * the lock, because the customer may have earned since the job queued them. Keyed per customer per
     * UTC run day, so a second run that day is a duplicate.
     *
     * @param string $now UTC MySQL datetime of the run; also the row's created_at.
     * @return Outcome awarded (the EXPIRE row), duplicate, or skipped( REASON_NOT_DUE ).
     */
    public function expire( int $user_id, string $now ): Outcome {
        $draft = new LedgerEntry(
            null,
            $user_id,
            0,
            0,
            LedgerType::EXPIRE,
            LedgerSource::EXPIRY,
            ReferenceType::USER,
            $user_id,
            null,
            null,
            null,
            null,
            null,
            $now,
            IdempotencyKey::compose( LedgerType::EXPIRE, $user_id, ReferenceType::USER, $user_id, gmdate( 'Ymd', Dates::to_timestamp( $now ) ) )
        );

        return $this->write_keyed(
            $draft,
            function ( Customer $customer ) use ( $now ): int {
                $policy  = ExpiryPolicy::current();
                $balance = max( 0, $customer->get_balance() );

                if ( ! $policy->is_due( $customer, $now ) ) {
                    return 0;
                }

                if ( ! $policy->is_batch() ) {
                    return -$balance;
                }

                return -min( array_sum( array_column( $this->lots->due_lots( $customer->get_user_id(), $now ), 'remaining' ) ), $balance );
            },
            self::REASON_NOT_DUE
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
            self::REASON_ORDER_REVERSED,
            $refund_row->get_id()
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
     * @param callable|null $points_under_lock Given the locked Customer, returns the row's signed points; 0 writes nothing.
     * @param string        $skip_reason       Outcome::skipped's reason when $points_under_lock returns 0.
     * @param int|null      $source_ledger_id  The row this one reverses: a negative row takes from its lot first, and a
     *                                         positive row (a restore) is dated like the lot it took from.
     */
    private function write_keyed( LedgerEntry $draft, ?callable $points_under_lock = null, string $skip_reason = '', ?int $source_ledger_id = null ): Outcome {
        $key = (string) $draft->get_idempotency_key();

        return $this->under_lock(
            $draft->get_user_id(),
            function ( Customer $customer ) use ( $draft, $key, $points_under_lock, $skip_reason, $source_ledger_id ): Outcome {
                // Under the lock a concurrent write for this user has finished, so this sees its row.
                $existing = $this->ledger->find_by_idempotency_key( $key );

                if ( null !== $existing ) {
                    return Outcome::duplicate( $existing );
                }

                if ( $this->is_paid_to_another_customer( $draft ) ) {
                    return Outcome::skipped( self::REASON_ORDER_HAS_POINTS );
                }

                if ( ! $this->campaign_survives( $draft ) ) {
                    return Outcome::skipped( self::REASON_CAMPAIGN_DELETED );
                }

                if ( null !== $points_under_lock ) {
                    $points = (int) $points_under_lock( $customer );

                    if ( 0 === $points ) {
                        return Outcome::skipped( $skip_reason );
                    }

                    $draft = $draft->with_points( $points );
                }

                $persisted = $this->append( $draft, $customer, true, $source_ledger_id );

                return null === $persisted ? Outcome::duplicate( $this->existing_for( $key ) ) : Outcome::awarded( $persisted );
            }
        );
    }

    /**
     * Whether $draft is an order earn (any trigger) and the order still pays another customer: it was
     * reassigned after it earned, so earning again would pay one order twice. A locking read, under the
     * customer lock: the two customers' keys differ, so no index ties them, and a plain read in an older
     * snapshot could miss the other's row. Once the other customer's points are clawed back in full, the
     * order can earn for its new customer.
     */
    private function is_paid_to_another_customer( LedgerEntry $draft ): bool {
        $order_id = $draft->get_order_id();

        if ( LedgerType::EARN !== $draft->get_type() || null === $order_id ) {
            return false;
        }

        return $this->ledger->net_points_of_others_for_order( $order_id, $draft->get_user_id(), true ) > 0;
    }

    /**
     * Holds an earn row's campaign under a shared lock until commit, so the row never names a deleted
     * campaign. Earn rows only: a reversal's campaign has rows, so it cannot be deleted.
     */
    private function campaign_survives( LedgerEntry $draft ): bool {
        $campaign_id = $draft->get_campaign_id();

        if ( LedgerType::EARN !== $draft->get_type() || null === $campaign_id ) {
            return true;
        }

        return $this->campaigns->lock_shared( $campaign_id );
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
                return $write( $this->customers->ensure_and_lock( $user_id ) );
            }
        );
    }

    /**
     * Inserts $draft at the locked balance, moves the aggregates and the
     * lots, and announces the row. Must run under_lock(), so balance_after chains.
     *
     * @param bool     $is_keyed         True turns a UNIQUE-key collision into null instead of an exception.
     * @param int|null $source_ledger_id See write_keyed().
     */
    private function append( LedgerEntry $draft, Customer $customer, bool $is_keyed, ?int $source_ledger_id = null ): ?LedgerEntry {
        // A balance from before lots existed gets them first, from history that excludes this row.
        $this->lots->backfill( $customer->get_user_id(), $customer->get_balance() );

        $balance_after = $customer->get_balance() + $draft->get_points();
        $to_insert     = $draft->with_persisted( 0, $balance_after );
        $insert_id     = $is_keyed ? $this->ledger->insert_unless_duplicate( $to_insert ) : $this->ledger->insert( $to_insert );

        if ( null === $insert_id ) {
            return null;
        }

        $this->apply_aggregates( $draft );

        $persisted = $to_insert->with_persisted( $insert_id, $balance_after );

        $policy  = ExpiryPolicy::current();
        $user_id = $customer->get_user_id();

        if ( LedgerType::EXPIRE !== $persisted->get_type() ) {
            $this->lots->record( $persisted, $customer->get_balance(), $source_ledger_id );
        } elseif ( $policy->is_batch() ) {
            // expire() sized the row from these lots, under this lock and at this created_at.
            $this->lots->take_due( $persisted, $this->lots->due_lots( $user_id, $persisted->get_created_at() ) );
        } else {
            $this->lots->take_all( $persisted );
        }

        $earliest   = $policy->is_batch() ? $this->lots->earliest_open_expiry( $user_id ) : null;
        $expires_at = $policy->customer_expires_at( $persisted, $customer, $earliest, Dates::now() );

        if ( $expires_at !== $customer->get_expires_at() ) {
            $this->customers->set_expires_at( $customer->get_user_id(), $expires_at );
        }

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
                esc_html(
                    sprintf( 'YayLoyalty: idempotency key "%s" collided but matches no ledger row.', $key )
                )
            );
        }

        return $existing;
    }

    /**
     * Debits one redemption: the REDEEM row and the customer aggregates, in
     * one transaction under the customer's row lock. A caller that writes
     * its own row beside this one (Engine\Redemption\CommitWriter) wraps both
     * in its own Transaction::run(), so they commit or roll back together.
     *
     * Preconditions: $draft is a REDEEM row with points < 0 and an
     * idempotency key composed by the caller
     * (`redeem:{user}:order:{order_id}:flexible_discount` for the flexible discount).
     * `order_id` is optional. Nothing slow or externally fallible may run inside.
     *
     * Returns exactly one of: Outcome::awarded( persisted row ), with
     * balance_after = locked balance + points (never below 0) and
     * lifetime_redeemed moved; the caller re-wraps it as Outcome::redeemed(),
     * because this service never sees the quote. Outcome::duplicate( existing
     * row ) when the key is already in the ledger; that check runs before the
     * balance check, so a re-fired hook stays a duplicate after the balance
     * moved. Outcome::skipped( Rewards\Result::REASON_INSUFFICIENT_BALANCE )
     * with nothing written when the locked balance cannot cover the points:
     * a redemption refuses, unlike a clawback.
     *
     * @param bool $allow_negative True skips the balance check. For undoing a restore whose refund was
     *                             deleted, where the debit must stand even if the points were spent since.
     * @throws \InvalidArgumentException When the draft is not a negative redeem row or lacks a key.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails.
     */
    public function redeem( LedgerEntry $draft, bool $allow_negative = false ): Outcome {
        $key = $draft->get_idempotency_key();

        if ( LedgerType::REDEEM !== $draft->get_type() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: redeem() writes REDEEM rows only.' );
        }

        if ( null === $key || '' === $key ) {
            throw new \InvalidArgumentException( 'YayLoyalty: a redeem row needs an idempotency key.' );
        }

        if ( $draft->get_points() >= 0 ) {
            throw new \InvalidArgumentException( 'YayLoyalty: a redeem row debits a negative number of points.' );
        }

        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        if ( $allow_negative ) {
            return $this->write_keyed( $draft );
        }

        return $this->write_keyed(
            $draft,
            static function ( Customer $customer ) use ( $draft ): int {
                return $customer->get_balance() + $draft->get_points() >= 0 ? $draft->get_points() : 0;
            },
            RedeemResult::REASON_INSUFFICIENT_BALANCE
        );
    }

    /**
     * Returns redeemed points after a cancel or a refund: a REFUND_REDEEM
     * row that raises the balance and lowers lifetime_redeemed, keyed by the
     * caller on the reversing reference. The caller
     * (Engine\Redemption\Reversal) decides the amount under the customer
     * lock and moves the redemption row in the same transaction. The balance
     * is never checked.
     *
     * @return Outcome awarded (the persisted row) or duplicate (the existing row).
     * @throws \InvalidArgumentException When the draft is not a positive refund_redeem row or lacks a key.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails.
     */
    public function refund_redeem( LedgerEntry $draft ): Outcome {
        $key = $draft->get_idempotency_key();

        if ( LedgerType::REFUND_REDEEM !== $draft->get_type() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: refund_redeem() writes REFUND_REDEEM rows only.' );
        }

        if ( null === $key || '' === $key ) {
            throw new \InvalidArgumentException( 'YayLoyalty: a refund_redeem row needs an idempotency key.' );
        }

        if ( $draft->get_points() < 1 ) {
            throw new \InvalidArgumentException( 'YayLoyalty: a refund_redeem row returns a positive number of points.' );
        }

        LedgerSource::assert( $draft->get_source() );
        ReferenceType::assert( $draft->get_reference_type() );

        return $this->write_keyed( $draft );
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
            $activity_at
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
