<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\TierChangeReason;
use YayLoyalty\Models\Tier;

defined( 'ABSPATH' ) || exit;

/**
 * The tier move rules, in Lifetime and Rolling mode. Pure: no WordPress call and no repository, so the rule is testable on
 * plain PHP. TierEvaluator gathers the state, asks this class what to do, then writes.
 */
final class TierDecision {

    /** Raise the customer to the target when it outranks their tier; never lower them. */
    public const MOVE_UP = 'up';

    /** Set the customer to the target, up or down. */
    public const MOVE_TO_TARGET = 'to_target';

    /** Rolling: leave the hold dates as stored. */
    public const HOLD_KEEP = 'keep';

    /** Rolling: start a hold from now for the window's length. */
    public const HOLD_START = 'start';

    /** Rolling: clear both hold dates. */
    public const HOLD_CLEAR = 'clear';

    /** Rolling: a positive row, a re-evaluation or a new floor. Up only, to the best of window tier and floor. */
    public const KIND_UP = 'up';

    /** Rolling: a negative row. During an active hold the qualifying tier protects the customer. */
    public const KIND_DOWN = 'down';

    /** Rolling: hold end, placement, a floor ending or a deleted tier. Exactly the best of window tier and floor. */
    public const KIND_TO_TARGET = 'to_target';

    /** Rolling: the switch from Lifetime. Never moves; only starts or clears the hold. */
    public const KIND_HOLD_ONLY = 'hold_only';

    private const SOURCE_FLOOR      = 'floor';
    private const SOURCE_WINDOW     = 'window';
    private const SOURCE_QUALIFYING = 'qualifying';

    /**
     * The tier a customer is due: the live tier with the highest threshold their total reaches, or the floor
     * when it outranks that.
     *
     * @param Tier[]    $live  Live tiers, any order.
     * @param Tier|null $floor The manual floor, only while it is live and has not ended.
     */
    public static function target( array $live, int $total, ?Tier $floor ): ?Tier {
        $qualify = null;

        foreach ( $live as $tier ) {
            if ( $tier->get_threshold() <= $total && ( null === $qualify || $tier->get_threshold() > $qualify->get_threshold() ) ) {
                $qualify = $tier;
            }
        }

        if ( null !== $floor && ( null === $qualify || $floor->get_tier_rank() > $qualify->get_tier_rank() ) ) {
            return $floor;
        }

        return $qualify;
    }

    /**
     * How a committed ledger row may move its customer. Null when it may not: the bonus row (an entry bonus
     * never triggers a further upgrade in the same evaluation), a row that leaves points earned alone, or zero.
     *
     * @return array{move: string, reason: string}|null
     */
    public static function ledger_policy( string $type, string $source, int $points ): ?array {
        if ( 0 === $points || LedgerSource::TIER_BONUS === $source || ! LedgerType::affects_lifetime_earned( $type ) ) {
            return null;
        }

        switch ( $type ) {
            case LedgerType::REFUND_EARN:
                $reason = TierChangeReason::REFUND;
                break;
            case LedgerType::ADJUST:
                $reason = TierChangeReason::ADJUSTMENT;
                break;
            default:
                $reason = TierChangeReason::EARN;
        }

        return [
            'move'   => $points > 0 ? self::MOVE_UP : self::MOVE_TO_TARGET,
            'reason' => $reason,
        ];
    }

    /**
     * Where the customer ends up.
     *
     * @param int|null  $current_id   The stored tier_id, which may name a deleted tier.
     * @param int       $current_rank The stored tier's rank while it is live; 0 when it is NULL or deleted.
     * @param Tier|null $target       target()'s answer.
     * @param string    $move         MOVE_UP or MOVE_TO_TARGET.
     * @return array{changed: bool, to: Tier|null, is_upgrade: bool} `to` is the resulting tier when changed.
     */
    public static function move( ?int $current_id, int $current_rank, ?Tier $target, string $move ): array {
        $target_rank = null === $target ? 0 : $target->get_tier_rank();

        if ( self::MOVE_UP === $move && $target_rank <= $current_rank ) {
            return self::unchanged();
        }

        $target_id = null === $target ? null : $target->get_id();

        if ( $target_id === $current_id ) {
            return self::unchanged();
        }

        return [
            'changed'    => true,
            'to'         => $target,
            'is_upgrade' => null !== $target && $target_rank > $current_rank,
        ];
    }

    /**
     * Where a Rolling customer ends up, and what happens to their hold. "Best" is the highest-ranked
     * candidate; on a tie the floor wins, then the window tier, then the qualifying tier.
     *
     * @param int|null  $current_id      The stored tier_id, which may name a deleted tier.
     * @param int       $current_rank    The stored tier's rank while it is live; 0 when it is NULL or deleted.
     * @param Tier|null $window_tier     target() of the window total, with no floor.
     * @param Tier|null $qualifying_tier target() of the active hold's qualifying total; null without a hold.
     * @param Tier|null $floor           The manual floor, only while it is live and has not ended.
     * @param bool      $hold_active     A tier and a tier_held_until after now.
     * @param string    $kind            One of the KIND_* constants.
     * @return array{changed: bool, to: Tier|null, is_upgrade: bool, hold: string} `hold` is a HOLD_* constant.
     */
    public static function rolling_move( ?int $current_id, int $current_rank, ?Tier $window_tier, ?Tier $qualifying_tier, ?Tier $floor, bool $hold_active, string $kind ): array {
        if ( self::KIND_HOLD_ONLY === $kind ) {
            $is_floor_held = null !== $floor && $floor->get_tier_rank() >= self::rank( $window_tier );

            return self::unchanged() + [ 'hold' => null !== $current_id && ! $is_floor_held ? self::HOLD_START : self::HOLD_CLEAR ];
        }

        [ $target, $target_source ] = self::best(
            [
                self::SOURCE_FLOOR  => $floor,
                self::SOURCE_WINDOW => $window_tier,
            ]
        );

        if ( self::KIND_UP === $kind ) {
            $move = self::move( $current_id, $current_rank, $target, self::MOVE_UP );

            return $move + [ 'hold' => $move['changed'] ? self::hold_for( $target_source ) : self::HOLD_KEEP ];
        }

        if ( self::KIND_DOWN === $kind && $hold_active ) {
            if ( self::rank( $window_tier ) >= $current_rank || self::rank( $qualifying_tier ) >= $current_rank ) {
                return self::unchanged() + [ 'hold' => self::HOLD_KEEP ];
            }

            [ $target, $target_source ] = self::best(
                [
                    self::SOURCE_FLOOR      => $floor,
                    self::SOURCE_WINDOW     => $window_tier,
                    self::SOURCE_QUALIFYING => $qualifying_tier,
                ]
            );
        }

        // KIND_TO_TARGET, and KIND_DOWN with or without an active hold.
        return self::move( $current_id, $current_rank, $target, self::MOVE_TO_TARGET ) + [ 'hold' => self::hold_for( $target_source ) ];
    }

    /**
     * The highest-ranked candidate and the key it came under; earlier keys win a tie.
     *
     * @param array<string, Tier|null> $candidates In tie-break order.
     * @return array{0: Tier|null, 1: string|null}
     */
    private static function best( array $candidates ): array {
        $best   = null;
        $source = null;

        foreach ( $candidates as $key => $tier ) {
            if ( null !== $tier && ( null === $best || $tier->get_tier_rank() > $best->get_tier_rank() ) ) {
                $best   = $tier;
                $source = $key;
            }
        }

        return [ $best, $source ];
    }

    /** The hold after a move to a tier that came from $source: the window starts one, the qualifying tier keeps it. */
    private static function hold_for( ?string $source ): string {
        if ( self::SOURCE_WINDOW === $source ) {
            return self::HOLD_START;
        }

        return self::SOURCE_QUALIFYING === $source ? self::HOLD_KEEP : self::HOLD_CLEAR;
    }

    private static function rank( ?Tier $tier ): int {
        return null === $tier ? 0 : $tier->get_tier_rank();
    }

    /** @return array{changed: bool, to: Tier|null, is_upgrade: bool} */
    private static function unchanged(): array {
        return [
            'changed'    => false,
            'to'         => null,
            'is_upgrade' => false,
        ];
    }
}
