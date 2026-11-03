<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The one builder of a redemption's ledger draft, so the commit and its reversals compose their
 * idempotency keys the same way. Builds only: LedgerService writes the row.
 */
final class RedemptionDraft {

    /**
     * A draft for the redemption's customer and order, carrying its redemption id and keyed
     * `{type}:{user}:{reference_type}:{reference_id}:{qualifier}`. balance_after is computed under the lock.
     *
     * @param string $type      LedgerType::REDEEM (negative $points) or LedgerType::REFUND_REDEEM (positive).
     * @param int    $points    Signed.
     * @param string $qualifier `flexible_discount` for the flexible discount, reward_qualifier() for a reward row.
     */
    public static function of( int $user_id, ?int $order_id, int $redemption_id, string $type, int $points, string $reference_type, int $reference_id, ?string $note = null, string $qualifier = RewardType::FLEXIBLE_DISCOUNT ): LedgerEntry {
        return new LedgerEntry(
            null,
            $user_id,
            $points,
            0,
            $type,
            LedgerSource::ORDER,
            $reference_type,
            $reference_id,
            null,
            $redemption_id,
            $order_id,
            $note,
            null,
            Dates::now(),
            IdempotencyKey::compose( $type, $user_id, $reference_type, $reference_id, $qualifier )
        );
    }

    /** The key qualifier of a reward's row: `reward_{id}`, so two rewards on one order never share a key. */
    public static function reward_qualifier( int $reward_id ): string {
        return 'reward_' . $reward_id;
    }

    /**
     * `flexible_discount` for a row of the flexible discount's reward, reward_qualifier() for any other. A null $reward is never
     * the flexible discount's, because the built-in reward can't be deleted.
     */
    public static function qualifier_for( Redemption $row, ?Reward $reward ): string {
        return RedemptionService::is_flexible_discount( $reward ) ? RewardType::FLEXIBLE_DISCOUNT : self::reward_qualifier( $row->get_reward_id() );
    }
}
