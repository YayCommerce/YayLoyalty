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
 * reference may move points once per trigger (earn) or per reward type (redeem). Nothing else goes in,
 * so a second hook, a status bounce or a retry composes the same key and collides on the index.
 */
final class IdempotencyKey {

    /** Matches ledger.idempotency_key varchar(96): the longest vocabulary with two BIGINT ids is 82. */
    public const MAX_LENGTH = 96;

    private const SEPARATOR = ':';

    /**
     * @param string $type           A LedgerType constant.
     * @param int    $user_id        Whose balance moves.
     * @param string $reference_type A ReferenceType constant.
     * @param int    $reference_id   The id in that namespace (order id, comment id, user id, year, redemption id).
     * @param string $qualifier      A CampaignTrigger for earn/refund_earn rows, a RewardType for redeem/refund_redeem rows, and the UTC `Ymd` run day for expire rows. Any build's, so a Pro value still composes after a downgrade.
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
                throw new \InvalidArgumentException( sprintf( 'YayLoyalty: an expire key needs a Ymd day, not "%s".', $qualifier ) );
            }
        } elseif ( $is_redeem ? ! RewardType::is_known( $qualifier ) : ! CampaignTrigger::is_known( $qualifier ) ) {
            throw new \InvalidArgumentException( sprintf( 'YayLoyalty: unknown %s "%s".', $is_redeem ? 'reward type' : 'campaign trigger', $qualifier ) );
        }

        $key = implode( self::SEPARATOR, [ $type, (string) $user_id, $reference_type, (string) $reference_id, $qualifier ] );

        if ( strlen( $key ) > self::MAX_LENGTH ) {
            throw new \InvalidArgumentException( 'YayLoyalty: idempotency key exceeds the column width.' );
        }

        return $key;
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
            throw new \InvalidArgumentException( sprintf( 'YayLoyalty: "%s" is not an idempotency key.', $key ) );
        }

        return $parts[4];
    }
}
