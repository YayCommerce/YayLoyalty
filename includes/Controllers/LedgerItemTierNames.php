<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Repositories\TierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `tier_name` to ledger items, so the admin can label an entry bonus "{Tier} tier bonus" and a
 * multiplied earn row "… · {Tier} ×1.25". Tiers are soft-deleted, so the name still resolves after the
 * tier is removed. One query per page of items.
 */
final class LedgerItemTierNames {

    /**
     * @param array<int, array<string, mixed>> $items Ledger items as LedgerEntry::to_array() shapes them.
     * @return array<int, array<string, mixed>> The same items, each with `tier_name`: the tier's name on a
     *         `tier_bonus` row or a row with a `tier_id`, else null.
     */
    public static function attach( array $items ): array {
        $ids = [];

        foreach ( $items as $item ) {
            $tier_id = self::tier_id_of( $item );

            if ( null !== $tier_id ) {
                $ids[] = $tier_id;
            }
        }

        $names = ( new TierRepository() )->names_by_id( $ids );

        foreach ( $items as $index => $item ) {
            $tier_id = self::tier_id_of( $item );

            $items[ $index ]['tier_name'] = null === $tier_id ? null : ( $names[ $tier_id ] ?? null );
        }

        return $items;
    }

    /**
     * The tier an item names: the tier a `tier_bonus` row was paid for, else the tier whose multiplier
     * an earn row used, else null.
     *
     * @param array<string, mixed> $item
     */
    private static function tier_id_of( array $item ): ?int {
        if ( LedgerSource::TIER_BONUS === ( $item['source'] ?? '' ) ) {
            return empty( $item['reference_id'] ) ? null : (int) $item['reference_id'];
        }

        return empty( $item['tier_id'] ) ? null : (int) $item['tier_id'];
    }
}
