<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Repositories\TierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `tier_name` to ledger items, so the admin can label an entry bonus "{Tier} tier bonus". Tiers are
 * soft-deleted, so the name still resolves after the tier is removed. One query per page of items.
 */
final class LedgerItemTierNames {

    /**
     * @param array<int, array<string, mixed>> $items Ledger items as LedgerEntry::to_array() shapes them.
     * @return array<int, array<string, mixed>> The same items, each with `tier_name`: the tier's name on a
     *         `tier_bonus` row, else null.
     */
    public static function attach( array $items ): array {
        $ids = [];

        foreach ( $items as $item ) {
            if ( self::is_bonus( $item ) ) {
                $ids[] = (int) $item['reference_id'];
            }
        }

        $tiers = ( new TierRepository() )->find_many( $ids );

        foreach ( $items as $index => $item ) {
            $tier = self::is_bonus( $item ) ? ( $tiers[ (int) $item['reference_id'] ] ?? null ) : null;

            $items[ $index ]['tier_name'] = null === $tier ? null : $tier->get_name();
        }

        return $items;
    }

    /** @param array<string, mixed> $item */
    private static function is_bonus( array $item ): bool {
        return LedgerSource::TIER_BONUS === ( $item['source'] ?? '' ) && ! empty( $item['reference_id'] );
    }
}
