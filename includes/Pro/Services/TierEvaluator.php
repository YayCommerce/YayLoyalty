<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\TierChangeReason;
use YayLoyalty\Enums\TierMode;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Tier;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Services\CustomerActivityService;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The only writer of the customer tier columns (D20 in the tiers design), the rolling hold's two columns
 * included, in Lifetime and Rolling mode. It runs after the ledger commit, never inside LedgerService's
 * lock, and takes the customer row lock in its own transaction. TierDecision decides the move; this class
 * gathers the state and writes the columns, the `tier_changed` activity row and the entry bonus, then fires
 * `yayloyalty_tier_changed` after commit.
 *
 * Lifetime qualifies on points earned and writes no hold column. Rolling qualifies on the window total
 * (LedgerRepository::window_total()) and, during an active hold, lets the qualifying total keep the tier.
 *
 * Every entry point does nothing while tiers are off or when the user no longer exists.
 */
final class TierEvaluator {

    /** @var CustomerRepository */
    private $customers;

    /** @var TierRepository */
    private $tiers;

    /** @var LedgerService */
    private $ledger;

    /** @var LedgerRepository */
    private $ledger_rows;

    /** @var CustomerActivityService */
    private $activity;

    public function __construct( CustomerRepository $customers, TierRepository $tiers, LedgerService $ledger, CustomerActivityService $activity, LedgerRepository $ledger_rows ) {
        $this->customers   = $customers;
        $this->tiers       = $tiers;
        $this->ledger      = $ledger;
        $this->activity    = $activity;
        $this->ledger_rows = $ledger_rows;
    }

    /** One of LedgerService's construction sites: the entry bonus is a ledger row. */
    public static function build(): self {
        $customers = new CustomerRepository();
        $ledger    = new LedgerRepository();

        return new self(
            $customers,
            new TierRepository(),
            new LedgerService( $ledger, $customers, new CampaignRepository() ),
            new CustomerActivityService( new CustomerMetaRepository(), $customers, $ledger ),
            $ledger
        );
    }

    /** Whether the merchant turned tiers on. */
    public static function is_enabled(): bool {
        $settings = SettingsHelper::get_settings();

        return true === ( $settings['tiers_enabled'] ?? false );
    }

    /** TierMode::ROLLING only when the merchant picked it; TierMode::LIFETIME otherwise. */
    public static function mode(): string {
        $settings = SettingsHelper::get_settings();

        return TierMode::ROLLING === ( $settings['tiers_mode'] ?? null ) ? TierMode::ROLLING : TierMode::LIFETIME;
    }

    /** The rolling window's length in months, the stored setting or its default. */
    public static function rolling_months(): int {
        $settings = SettingsHelper::get_settings();
        $months   = $settings['tiers_rolling_months'] ?? null;

        return TierMode::is_rolling_months( $months ) ? (int) $months : TierMode::DEFAULT_ROLLING_MONTHS;
    }

    /** A committed ledger row: moves its customer as TierDecision::ledger_policy() allows. */
    public function on_ledger_entry( LedgerEntry $entry ): void {
        $policy = TierDecision::ledger_policy( $entry->get_type(), $entry->get_source(), $entry->get_points() );

        if ( null === $policy ) {
            return;
        }

        $this->apply(
            $entry->get_user_id(),
            static function () use ( $policy ): array {
                $kind = TierDecision::MOVE_UP === $policy['move'] ? TierDecision::KIND_UP : TierDecision::KIND_DOWN;

                return self::plan( $policy['move'], $policy['reason'], $kind );
            },
            null,
            true
        );
    }

    /**
     * One customer of a sweep. Placement sets the exact tier and pays no bonus. Re-evaluation moves a
     * customer whose tier or floor tier was deleted to their target, and only raises everyone else. The
     * mode sweeps do re-evaluation's work too, since a request merges it away: to_rolling then starts or
     * clears each hold without moving anyone, and to_lifetime clears every hold and only raises, on
     * Lifetime rules. Both run their own path whatever tiers_mode holds.
     *
     * @param string $mode A TierSweep::MODE_* constant.
     */
    public function sweep_one( int $user_id, string $mode ): void {
        if ( TierSweep::MODE_PLACEMENT === $mode ) {
            $this->apply(
                $user_id,
                static function (): array {
                    return self::plan( TierDecision::MOVE_TO_TARGET, TierChangeReason::PLACEMENT, TierDecision::KIND_TO_TARGET );
                },
                null,
                false
            );

            return;
        }

        $this->apply(
            $user_id,
            static function ( Customer $customer, array $live ) use ( $mode ): array {
                $tier_id    = $customer->get_tier_id();
                $floor_id   = $customer->get_tier_manual_id();
                $tier_gone  = null !== $tier_id && ! isset( $live[ $tier_id ] );
                $floor_gone = null !== $floor_id && ! isset( $live[ $floor_id ] );

                if ( $tier_gone || $floor_gone ) {
                    $plan = self::plan( TierDecision::MOVE_TO_TARGET, TierChangeReason::TIER_DELETED, TierDecision::KIND_TO_TARGET );

                    if ( $floor_gone ) {
                        $plan['floor'] = 'clear';
                    }
                } elseif ( TierSweep::MODE_TO_LIFETIME === $mode ) {
                    $plan = self::plan( TierDecision::MOVE_UP, TierChangeReason::MODE_CHANGE, TierDecision::KIND_UP );
                } elseif ( TierSweep::MODE_TO_ROLLING === $mode ) {
                    $plan = self::plan( TierDecision::MOVE_UP, TierChangeReason::TIER_DELETED, TierDecision::KIND_HOLD_ONLY );
                } else {
                    $plan = self::plan( TierDecision::MOVE_UP, TierChangeReason::THRESHOLD_CHANGE, TierDecision::KIND_UP );
                }

                if ( TierSweep::MODE_TO_LIFETIME === $mode ) {
                    $plan['mode']       = TierMode::LIFETIME;
                    $plan['clear_hold'] = true;
                } elseif ( TierSweep::MODE_TO_ROLLING === $mode ) {
                    $plan['mode'] = TierMode::ROLLING;
                }

                return $plan;
            },
            null,
            true
        );
    }

    /**
     * A rolling hold ended: moves the customer to exactly the tier their window and floor give, which
     * renews the hold when that is their tier. Does nothing in Lifetime, or when, under the lock, the hold
     * no longer ends by now (an evaluation since the daily job read the id started a new one).
     */
    public function end_hold( int $user_id ): void {
        if ( TierMode::ROLLING !== self::mode() ) {
            return;
        }

        $this->apply(
            $user_id,
            static function ( Customer $customer ): ?array {
                $held_until = $customer->get_tier_held_until();

                if ( null === $customer->get_tier_id() || null === $held_until || $held_until > Dates::now() ) {
                    return null;
                }

                return self::plan( TierDecision::MOVE_TO_TARGET, TierChangeReason::HOLD_END, TierDecision::KIND_TO_TARGET );
            },
            null,
            true
        );
    }

    /**
     * Sets a manual floor: the customer is at least this tier until it ends. Raises them now when it
     * outranks their tier; a floor at or below their tier is stored and changes nothing else.
     *
     * @param int|null $days 1–3650, or null for no end.
     * @throws \InvalidArgumentException When the tier is not live.
     */
    public function assign_floor( int $user_id, int $tier_id, ?int $days, int $actor_id ): void {
        if ( null === $this->tiers->find_live( $tier_id ) ) {
            throw new \InvalidArgumentException( esc_html( sprintf( 'YayLoyalty: tier %d is not live.', $tier_id ) ) );
        }

        $until = null === $days ? null : Dates::offset( $days * DAY_IN_SECONDS );

        $this->apply(
            $user_id,
            static function () use ( $tier_id, $until ): array {
                $plan = self::plan( TierDecision::MOVE_UP, TierChangeReason::MANUAL, TierDecision::KIND_UP );

                $plan['floor']       = 'set';
                $plan['floor_id']    = $tier_id;
                $plan['floor_until'] = $until;

                return $plan;
            },
            $actor_id,
            true
        );
    }

    /**
     * Ends a manual floor, at its end date or by staff, and moves the customer to the tier their points
     * qualify for, which may be lower.
     *
     * @param int|null $actor_id The staff member who removed it; null for the daily end step.
     */
    public function clear_floor( int $user_id, ?int $actor_id ): void {
        $this->apply(
            $user_id,
            static function (): array {
                $plan = self::plan( TierDecision::MOVE_TO_TARGET, TierChangeReason::MANUAL_END, TierDecision::KIND_TO_TARGET );

                $plan['floor'] = 'clear';

                return $plan;
            },
            $actor_id,
            true
        );
    }

    /**
     * @param string $move The Lifetime move, a TierDecision::MOVE_* constant.
     * @param string $kind The Rolling move, a TierDecision::KIND_* constant.
     * @return array{move: string, reason: string, kind: string, mode: string|null, clear_hold: bool, floor: string, floor_id: int|null, floor_until: string|null}
     *         `floor` is keep, clear or set. `mode` null follows tiers_mode; a mode sweep names its own.
     *         `clear_hold` clears both hold columns, for the switch to Lifetime.
     */
    private static function plan( string $move, string $reason, string $kind ): array {
        return [
            'move'        => $move,
            'reason'      => $reason,
            'kind'        => $kind,
            'mode'        => null,
            'clear_hold'  => false,
            'floor'       => 'keep',
            'floor_id'    => null,
            'floor_until' => null,
        ];
    }

    /**
     * Evaluates one customer under their row lock and writes the result.
     *
     * @param callable $plan_for ( Customer $customer, array<int, Tier> $live ): ?array, plan()'s shape, or null
     *                           to leave the customer untouched.
     */
    private function apply( int $user_id, callable $plan_for, ?int $actor_id, bool $pays_bonus ): void {
        if ( ! self::is_enabled() || false === get_userdata( $user_id ) ) {
            return;
        }

        Transaction::run(
            function () use ( $user_id, $plan_for, $actor_id, $pays_bonus ): void {
                $customer = $this->customers->ensure_and_lock( $user_id );
                $live     = [];

                foreach ( $this->tiers->live() as $tier ) {
                    $live[ (int) $tier->get_id() ] = $tier;
                }

                $plan = $plan_for( $customer, $live );

                if ( null === $plan ) {
                    return;
                }

                $now     = Dates::now();
                $columns = [];

                $floor_id    = $customer->get_tier_manual_id();
                $floor_until = $customer->get_tier_manual_until();

                if ( 'clear' === $plan['floor'] ) {
                    $floor_id    = null;
                    $floor_until = null;
                } elseif ( 'set' === $plan['floor'] ) {
                    $floor_id    = $plan['floor_id'];
                    $floor_until = $plan['floor_until'];
                }

                if ( 'keep' !== $plan['floor'] ) {
                    $columns['tier_manual_id']    = $floor_id;
                    $columns['tier_manual_until'] = $floor_until;
                }

                $floor = null !== $floor_id && isset( $live[ $floor_id ] ) && ( null === $floor_until || $floor_until > $now )
                    ? $live[ $floor_id ]
                    : null;

                $current_id   = $customer->get_tier_id();
                $current_rank = null !== $current_id && isset( $live[ $current_id ] ) ? $live[ $current_id ]->get_tier_rank() : 0;
                $mode         = $plan['mode'] ?? self::mode();

                if ( TierMode::ROLLING === $mode ) {
                    $months   = self::rolling_months();
                    $total    = $this->ledger_rows->window_total( $user_id, Dates::add_months( $now, -$months ), $now );
                    $decision = $this->rolling_decision( $customer, $live, $floor, $current_rank, $total, $months, $plan['kind'], $now );

                    if ( TierDecision::HOLD_START === $decision['hold'] ) {
                        $columns['tier_held_from']  = $now;
                        $columns['tier_held_until'] = Dates::add_months( $now, $months );
                    } elseif ( TierDecision::HOLD_CLEAR === $decision['hold'] ) {
                        $columns['tier_held_from']  = null;
                        $columns['tier_held_until'] = null;
                    }
                } else {
                    $total    = $customer->get_lifetime_earned();
                    $decision = TierDecision::move( $current_id, $current_rank, TierDecision::target( $live, $total, $floor ), $plan['move'] );

                    if ( $plan['clear_hold'] ) {
                        $columns['tier_held_from']  = null;
                        $columns['tier_held_until'] = null;
                    }
                }

                $columns['tier_evaluated_at'] = $now;

                if ( $decision['changed'] ) {
                    $to = $decision['to'];

                    $columns['tier_id']          = null === $to ? null : $to->get_id();
                    $columns['tier_achieved_at'] = $now;

                    $this->activity->record_tier_changed(
                        $user_id,
                        $current_id,
                        null === $to ? null : $to->get_id(),
                        $this->name_of( $current_id, $live ),
                        null === $to ? '' : $to->get_name(),
                        $plan['reason'],
                        $actor_id,
                        $now
                    );

                    if ( $pays_bonus && $decision['is_upgrade'] && null !== $to ) {
                        $total += $this->pay_bonus( $user_id, $to, $now );
                    }

                    $this->announce( $user_id, $current_id, null === $to ? null : $to->get_id(), $plan['reason'] );
                }

                // After the bonus, so the progress shown matches the points earned; the bonus row's own evaluation writes nothing.
                $columns['tier_progress_value'] = (string) $total;

                $this->customers->set_tier_state( $user_id, $columns );
            }
        );
    }

    /**
     * The Rolling move for one customer under their lock. While the hold is active, the qualifying total is
     * what was earned in the L months before the hold started plus staff adjustments since, where L is the
     * stored hold's own length, so a months change never moves an existing hold's window. A hand-edited hold
     * with no start, or one not before its end, counts as $months long; a missing start reads as the end
     * less $months.
     *
     * @param array<int, Tier> $live
     * @return array{changed: bool, to: Tier|null, is_upgrade: bool, hold: string} TierDecision::rolling_move().
     */
    private function rolling_decision( Customer $customer, array $live, ?Tier $floor, int $current_rank, int $window, int $months, string $kind, string $now ): array {
        $user_id     = $customer->get_user_id();
        $current_id  = $customer->get_tier_id();
        $held_until  = $customer->get_tier_held_until();
        $hold_active = null !== $current_id && null !== $held_until && $held_until > $now;
        $qualifying  = null;

        if ( $hold_active ) {
            $held_from = $customer->get_tier_held_from();
            $length    = null === $held_from ? 0 : Dates::whole_months_between( $held_from, $held_until );

            if ( 0 === $length ) {
                $length = $months;
            }

            if ( null === $held_from ) {
                $held_from = Dates::add_months( $held_until, -$months );
            }

            $qualifying_total = $this->ledger_rows->window_total( $user_id, Dates::add_months( $held_from, -$length ), $held_from )
                + $this->ledger_rows->staff_adjust_since( $user_id, $held_from );
            $qualifying       = TierDecision::target( $live, $qualifying_total, null );
        }

        return TierDecision::rolling_move(
            $current_id,
            $current_rank,
            TierDecision::target( $live, $window, null ),
            $qualifying,
            $floor,
            $hold_active,
            $kind
        );
    }

    /**
     * The tier's entry bonus, once per customer per tier, ever: the idempotency key turns a second payment
     * into a duplicate. Written through LedgerService::earn() with no award filter, so no daily cap clamps it.
     *
     * @return int The points added to points earned: 0 when the tier has no bonus or it was already paid.
     */
    private function pay_bonus( int $user_id, Tier $tier, string $now ): int {
        $bonus   = $tier->get_entry_bonus();
        $tier_id = (int) $tier->get_id();

        if ( $bonus < 1 ) {
            return 0;
        }

        $outcome = $this->ledger->earn(
            new LedgerEntry(
                null,
                $user_id,
                $bonus,
                0,
                LedgerType::EARN,
                LedgerSource::TIER_BONUS,
                ReferenceType::TIER,
                $tier_id,
                null,
                null,
                null,
                /* translators: %s: the tier's name. */
                sprintf( __( '%s tier bonus', 'yayloyalty' ), $tier->get_name() ),
                null,
                $now,
                IdempotencyKey::compose( LedgerType::EARN, $user_id, ReferenceType::TIER, $tier_id, IdempotencyKey::TIER_BONUS )
            )
        );

        return Outcome::AWARDED === $outcome->get_status() && null !== $outcome->get_entry() ? $outcome->get_entry()->get_points() : 0;
    }

    /**
     * The name of the tier the customer is leaving: live, deleted, or '' for none.
     *
     * @param array<int, Tier> $live
     */
    private function name_of( ?int $tier_id, array $live ): string {
        if ( null === $tier_id ) {
            return '';
        }

        $tier = $live[ $tier_id ] ?? $this->tiers->find_tier( $tier_id );

        return null === $tier ? '' : $tier->get_name();
    }

    private function announce( int $user_id, ?int $from_tier_id, ?int $to_tier_id, string $reason ): void {
        Transaction::after_commit(
            static function () use ( $user_id, $from_tier_id, $to_tier_id, $reason ): void {
                /**
                 * Fires after a customer's tier changed and the change is committed. The future hook for tier emails.
                 *
                 * @param int      $user_id
                 * @param int|null $from_tier_id Null when the customer had no tier.
                 * @param int|null $to_tier_id   Null when the customer is left with no tier.
                 * @param string   $reason       A TierChangeReason constant.
                 */
                do_action( 'yayloyalty_tier_changed', $user_id, $from_tier_id, $to_tier_id, $reason );
            }
        );
    }
}
