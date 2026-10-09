<?php
namespace YayLoyalty\Engine\Redemption;

use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The one builder of a slider redemption's ledger draft, so the commit and its reversals compose their
 * idempotency keys the same way. Builds only: LedgerService writes the row.
 */
final class RedemptionDraft {

    /**
     * A draft for the redemption's customer and order, carrying its redemption id and keyed
     * `{type}:{user}:{reference_type}:{reference_id}:flexible_discount`. balance_after is computed under the lock.
     *
     * @param string $type   LedgerType::REDEEM (negative $points) or LedgerType::REFUND_REDEEM (positive).
     * @param int    $points Signed.
     */
    public static function of( int $user_id, ?int $order_id, int $redemption_id, string $type, int $points, string $reference_type, int $reference_id, ?string $note = null ): LedgerEntry {
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
            IdempotencyKey::compose( $type, $user_id, $reference_type, $reference_id, RewardType::FLEXIBLE_DISCOUNT )
        );
    }
}
