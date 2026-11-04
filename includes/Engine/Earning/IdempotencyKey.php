<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\RewardType;

defined( 'ABSPATH' ) || exit;

/**
 * The string behind the ledger's UNIQUE guard, shared by earning and redemption, e.g.
 * "earn:7:order:1234:purchase" or "redeem:7:redemption:88:flexible_discount".
 * It holds the user, because a referral order pays two people. It holds a qualifier, because one
 * reference may move points once per trigger (earn), or once per reward type or per reward (redeem:
 * "redeem:7:order:1234:reward_5"). Nothing else goes in,
 * so a second hook, a status bounce or a retry composes the same key and collides on the index.
 */
final class IdempotencyKey {

    /** Matches ledger.idempotency_key varchar(96): the longest, a reward qualifier with three BIGINT ids, is 94. */
    public const MAX_LENGTH = 96;

    /** The qualifier of an order's one manual recovery earn (LedgerService::recover_order()) and its clawbacks. Not a CampaignTrigger. */
    public const RECOVERY = 'recovery';

    /** The qualifier of a tier's one-time entry bonus: an earn row referencing that tier. Not a CampaignTrigger. */
    public const TIER_BONUS = 'tier_bonus';

    private const SEPARATOR = ':';

    /**
     * @param string $type           A LedgerType constant.
     * @param int    $user_id        Whose balance moves.
     * @param string $reference_type A ReferenceType constant.
     * @param int    $reference_id   The id in that namespace (order id, comment id, user id, year, redemption id).
     * @param string $qualifier      A CampaignTrigger, or RECOVERY, for earn/refund_earn rows (RECOVERY is unknown to any other type), or TIER_BONUS for an earn row referencing a tier; a RewardType, or `reward_{id}` for one reward's row, for redeem/refund_redeem rows; and the UTC `Ymd` run day for expire rows. Any build's, so a Pro value still composes after a downgrade.
     * @throws \InvalidArgumentException When any part is outside its vocabulary or an id is not positive.
     */
    public static function compose( string $type, int $user_id, string $reference_type, int $reference_id, string $qualifier ): string {
        LedgerType::assert( $type );

        if ( $user_id < 1 ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an idempotency key needs a positive user id.' );
        }

        if ( null === ReferenceType::assert( $reference_type ) ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an idempotency key needs a reference type.' );
        }

        if ( $reference_id < 1 ) {
            throw new \InvalidArgumentException( 'YayLoyalty: an idempotency key needs a positive reference id.' );
        }

        $is_redeem = in_array( $type, [ LedgerType::REDEEM, LedgerType::REFUND_REDEEM ], true );

        if ( LedgerType::EXPIRE === $type ) {
            if ( 1 !== preg_match( '/^\d{8}$/', $qualifier ) ) {
                throw new \InvalidArgumentException( esc_html( sprintf( 'YayLoyalty: an expire key needs a Ymd day, not "%s".', $qualifier ) ) );
            }
        } elseif ( $is_redeem ? ! self::is_redeem_qualifier( $qualifier ) : ! self::is_earn_qualifier( $type, $reference_type, $qualifier ) ) {
            throw new \InvalidArgumentException( esc_html( sprintf( 'YayLoyalty: unknown %s "%s".', $is_redeem ? 'reward type' : 'campaign trigger', $qualifier ) ) );
        }

        $key = implode( self::SEPARATOR, [ $type, (string) $user_id, $reference_type, (string) $reference_id, $qualifier ] );

        if ( strlen( $key ) > self::MAX_LENGTH ) {
            throw new \InvalidArgumentException( 'YayLoyalty: idempotency key exceeds the column width.' );
        }

        return $key;
    }

    /** A CampaignTrigger, RECOVERY on an earn or refund_earn row, or TIER_BONUS on an earn row referencing a tier. */
    private static function is_earn_qualifier( string $type, string $reference_type, string $qualifier ): bool {
        if ( self::TIER_BONUS === $qualifier ) {
            return LedgerType::EARN === $type && ReferenceType::TIER === $reference_type;
        }

        if ( self::RECOVERY === $qualifier ) {
            return in_array( $type, [ LedgerType::EARN, LedgerType::REFUND_EARN ], true );
        }

        return CampaignTrigger::is_known( $qualifier );
    }

    /** A RewardType, or `reward_` followed by a positive reward id. */
    private static function is_redeem_qualifier( string $qualifier ): bool {
        return RewardType::is_known( $qualifier ) || 1 === preg_match( '/^reward_[1-9][0-9]*$/D', $qualifier );
    }

    /**
     * An award's key: one per user, reference and trigger.
     *
     * @throws \InvalidArgumentException As compose().
     */
    public static function for_earn( int $user_id, string $reference_type, int $reference_id, string $trigger ): string {
        return self::compose( LedgerType::EARN, $user_id, $reference_type, $reference_id, $trigger );
    }

    /**
     * The qualifier compose() put last: the trigger of an earn key, so a
     * reversal of that earn composes its own key with the same qualifier.
     *
     * @throws \InvalidArgumentException When $key does not have compose()'s five parts.
     */
    public static function qualifier_of( string $key ): string {
        $parts = explode( self::SEPARATOR, $key );

        if ( 5 !== count( $parts ) || '' === $parts[4] ) {
            throw new \InvalidArgumentException( esc_html( sprintf( 'YayLoyalty: "%s" is not an idempotency key.', $key ) ) );
        }

        return $parts[4];
    }
}
