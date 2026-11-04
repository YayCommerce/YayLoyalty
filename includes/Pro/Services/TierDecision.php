<?php
namespace YayLoyalty\Pro\Services;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\TierChangeReason;
use YayLoyalty\Models\Tier;

defined( 'ABSPATH' ) || exit;

/**
 * The tier move rule, in Lifetime mode. Pure: no WordPress call and no repository, so the rule is testable on
 * plain PHP. TierEvaluator gathers the state, asks this class what to do, then writes.
 */
final class TierDecision {

    /** Raise the customer to the target when it outranks their tier; never lower them. */
    public const MOVE_UP = 'up';

    /** Set the customer to the target, up or down. */
    public const MOVE_TO_TARGET = 'to_target';

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

    /** @return array{changed: bool, to: Tier|null, is_upgrade: bool} */
    private static function unchanged(): array {
        return [
            'changed'    => false,
            'to'         => null,
            'is_upgrade' => false,
        ];
    }
}
