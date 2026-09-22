<?php
namespace YayLoyalty\Engine\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\ReferenceType;

defined( 'ABSPATH' ) || exit;

/**
 * The one string behind the ledger's UNIQUE guard: "earn:7:order:1234:purchase".
 * Includes the user because one reference may legitimately pay two people
 * (a referral order pays advocate and friend), and the trigger because one
 * order may earn once per trigger — and nothing else, so two hooks, a status
 * bounce or a retry all compose the same key and collide on the index
 * instead of double-paying.
 */
final class IdempotencyKey {

    /** Matches ledger.idempotency_key varchar(96): the longest vocabulary with two BIGINT ids is 82. */
    public const MAX_LENGTH = 96;

    private const SEPARATOR = ':';

    /**
     * @param string $type           A LedgerType constant (earn today; refund types when clawbacks land).
     * @param int    $user_id        Whose balance moves.
     * @param string $reference_type A ReferenceType constant.
     * @param int    $reference_id   The id in that namespace (order id, comment id, user id, year).
     * @param string $trigger        A CampaignTrigger constant, any build's (a Pro trigger stays composable after a downgrade).
     * @throws \InvalidArgumentException When any part is outside its vocabulary or an id is not positive.
     */
    public static function compose( string $type, int $user_id, string $reference_type, int $reference_id, string $trigger ): string {
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

        if ( ! CampaignTrigger::is_known( $trigger ) ) {
            throw new \InvalidArgumentException( sprintf( 'YayLoyalty: unknown campaign trigger "%s".', $trigger ) );
        }

        $key = implode( self::SEPARATOR, [ $type, (string) $user_id, $reference_type, (string) $reference_id, $trigger ] );

        if ( strlen( $key ) > self::MAX_LENGTH ) {
            throw new \InvalidArgumentException( 'YayLoyalty: idempotency key exceeds the column width.' );
        }

        return $key;
    }
}
