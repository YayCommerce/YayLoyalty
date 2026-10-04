<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Points\CapPolicy;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The two moments of earning, one public method each. preview() is the
 * evaluation moment: gates, then the pure engine, nothing written.
 * award() is the award moment: the same evaluation again, then one
 * transaction. Both score at the Context's occurred_at — the order's
 * creation date — which is the only timestamp either can see, so a
 * campaign that ended between purchase and fulfilment still pays.
 */
final class EarningService {

    /** Fired after commit, once per awarded row, with ( Outcome $awarded, Context $ctx ). */
    public const AWARDED_ACTION = 'yayloyalty_points_awarded';

    private PointsEngine $engine;

    private LedgerService $ledger;

    private PendingPoints $pending;

    /** @var Gate[] In check order; the first reason wins. */
    private array $gates;

    /**
     * @param Gate[] $gates
     */
    public function __construct( PointsEngine $engine, LedgerService $ledger, PendingPoints $pending, array $gates ) {
        $this->engine  = $engine;
        $this->ledger  = $ledger;
        $this->pending = $pending;
        $this->gates   = array_values( $gates );
    }

    /**
     * Composition root: the engine with the build's handlers, the writer
     * (LedgerService's third construction site), the pending store and the
     * gates — ProgramGate first, then whatever `yayloyalty_earning_gates`
     * appends (Pro). Listeners are registered by the caller, not here.
     */
    public static function build(): self {
        $campaigns = new CampaignRepository();
        $customers = new CustomerRepository();

        $engine = new PointsEngine( Registry::with_lite_handlers( $campaigns ), $campaigns, new CapPolicy() );
        $ledger = new LedgerService( new LedgerRepository(), $customers );

        return new self( $engine, $ledger, new PendingPoints( $customers ), self::gates() );
    }

    /**
     * The `yayloyalty_earning_gates` filter's result, ProgramGate always
     * first: Pro appends, it never replaces.
     *
     * @return Gate[]
     */
    private static function gates(): array {
        $default = [ new ProgramGate() ];

        /**
         * Gates appended after ProgramGate. Internal seam, not a public API.
         *
         * @param Gate[] $gates
         */
        $gates = apply_filters( 'yayloyalty_earning_gates', $default );

        if ( ! is_array( $gates ) ) {
            Log::warning( 'EarningService', 'yayloyalty_earning_gates returned a non-array; using the default gate list' );

            return $default;
        }

        $valid = array_values(
            array_filter(
                $gates,
                static function ( $gate ) {
                    if ( $gate instanceof Gate ) {
                        return true;
                    }

                    Log::warning( 'EarningService', 'yayloyalty_earning_gates returned a non-Gate value (' . ( is_object( $gate ) ? get_class( $gate ) : gettype( $gate ) ) . '); ignoring it' );

                    return false;
                }
            )
        );

        return empty( $valid ) ? $default : $valid;
    }

    /**
     * The evaluation moment. Pure given its inputs: writes nothing, may run
     * any number of times, and returns the same Result for the same Context.
     * Not authoritative — the caller may stage its points as pending, and
     * award() re-evaluates rather than trusting a stored figure.
     *
     * @return Result Result::none( gate reason ) when a gate blocks, else the engine's answer.
     */
    public function preview( string $trigger, Context $ctx ): Result {
        $reason = $this->blocked_reason( $trigger, $ctx );

        if ( null !== $reason ) {
            return Result::none( $reason );
        }

        return $this->engine->evaluate( $trigger, $ctx );
    }

    /**
     * The award moment. Re-runs preview() at the same occurred_at, then
     * commits in one transaction: ledger row via LedgerService::earn()
     * (customer row locked first), pending release, order projections.
     *
     * Preconditions: $ctx built by ContextFactory::from_order() /
     * from_user() / from_comment(), never by hand from "now"; the ledger's
     * points_earned_today already on it. Staging need not have run.
     *
     * Postconditions: Outcome::skipped( reason ) and nothing written when a
     * gate or the engine says no; Outcome::awarded when exactly one row was
     * inserted; Outcome::duplicate when the idempotency key already existed
     * (nothing written, nothing thrown). `yayloyalty_points_awarded`
     * ( Outcome, Context ) fires after commit only, and only for awarded.
     *
     * @throws \InvalidArgumentException When no handler owns the trigger.
     * @throws \YayLoyalty\Database\QueryException When a write other than a duplicate fails (rolled back).
     */
    public function award( string $trigger, Context $ctx ): Outcome {
        $result = $this->preview( $trigger, $ctx );

        if ( ! $result->is_awarded() ) {
            return Outcome::skipped( $result->get_reason(), $result );
        }

        $order_id = $ctx->get_order_id();

        $outcome = Transaction::run(
            function () use ( $result, $ctx, $trigger, $order_id ): Outcome {
                $outcome = $this->ledger->earn( $this->draft_from( $result, $ctx, $trigger ) );

                if ( null !== $order_id && Outcome::AWARDED === $outcome->get_status() ) {
                    $this->pending->release( $order_id );
                    $this->pending->mark_awarded( $order_id, $outcome->get_entry() );
                }

                return $outcome;
            }
        );

        if ( Outcome::AWARDED !== $outcome->get_status() ) {
            return $outcome;
        }

        $awarded = Outcome::awarded( $outcome->get_entry(), $result );

        Transaction::after_commit(
            static function () use ( $awarded, $ctx ): void {
                /**
                 * Fires after an award commits, once per ledger row written.
                 *
                 * @param Outcome $awarded Carries the row and the engine Result.
                 * @param Context $ctx
                 */
                do_action( self::AWARDED_ACTION, $awarded, $ctx ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
            }
        );

        return $awarded;
    }

    /**
     * The EARN row an awarded Result becomes. Precondition: $result is
     * awarded and $ctx has a user. Postcondition: type EARN, points and
     * source/reference/campaign from the Result, order_id from the Context,
     * balance_after 0 (computed under the lock), actor_id null (a customer
     * is not an actor), created_at = Dates::now() (the write time; the
     * event time lives on the Context and in the key's reference), and
     * idempotency_key = IdempotencyKey::compose( EARN, user_id,
     * reference_type, reference_id, $trigger ).
     */
    private function draft_from( Result $result, Context $ctx, string $trigger ): LedgerEntry {
        $user_id        = (int) $ctx->get_user_id();
        $reference_type = (string) $result->get_reference_type();
        $reference_id   = (int) $result->get_reference_id();
        $campaign       = $result->get_campaign();

        return new LedgerEntry(
            null,
            $user_id,
            $result->get_points(),
            0,
            LedgerType::EARN,
            (string) $result->get_source(),
            $reference_type,
            $reference_id,
            $campaign ? $campaign->get_id() : null,
            null,
            $ctx->get_order_id(),
            null,
            null,
            Dates::now(),
            IdempotencyKey::compose( LedgerType::EARN, $user_id, $reference_type, $reference_id, $trigger )
        );
    }

    /** The gate chain: gates run in order and the first reason stops the rest. */
    private function blocked_reason( string $trigger, Context $ctx ): ?string {
        foreach ( $this->gates as $gate ) {
            $reason = $gate->check( $trigger, $ctx );

            if ( null !== $reason && '' !== $reason ) {
                return $reason;
            }
        }

        return null;
    }
}
