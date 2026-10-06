<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Result;
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
 * Earns points: preview() evaluates and writes nothing, award() evaluates again and writes.
 * Both score at the Context's occurred_at (the order's creation date), so a campaign that
 * ended between purchase and fulfilment still pays.
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
     * Composition root and a listed LedgerService construction site.
     * The caller registers the listeners.
     */
    public static function build(): self {
        $campaigns = new CampaignRepository();
        $customers = new CustomerRepository();

        $engine = PointsEngine::build( $campaigns );
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
     * Evaluates without writing. Not authoritative: award() evaluates again rather than trust a staged figure.
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
     * Evaluates again at the same occurred_at, then writes the ledger row, releases the pending
     * points and marks the order in one transaction. Build $ctx with ContextFactory, never from "now".
     * Returns skipped when a gate or the engine says no, and duplicate (nothing written, nothing thrown)
     * when the idempotency key exists. `yayloyalty_points_awarded` fires after commit, for awarded only.
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
     * The EARN row for an awarded Result. balance_after is 0 because LedgerService computes it under the lock.
     * actor_id is null: a customer is not an actor. created_at is the write time; the event time is on the Context.
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
