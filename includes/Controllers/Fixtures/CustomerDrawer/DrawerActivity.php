<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Activity-log rows — structured, not sentences. Wave A does not wire a
 * route or render these (see phase-07's `ActivityLogTab.tsx`, which shows
 * only its empty state); this exists so the row shape is decided now rather
 * than invented later, once the table it belongs to actually exists.
 *
 * `customer-drawer.copy.ts` would compose every sentence from `event_type` +
 * `data`, exactly as `dashboard-page.notice-copy.ts` does for dashboard
 * notices — never from a string stored here.
 */
final class DrawerActivity {

    /** The seven event kinds artboard 3d shows. */
    private const EVENT_TYPES = [
        'account_created',
        'points_adjusted',
        'tier_changed',
        'expiry_reset',
        'reward_redeemed',
        'balance_email_sent',
        'expiring_soon_email_sent',
    ];

    /**
     * @return array<int, array{id:int, occurred_at:string, event_type:string, data:array<string,mixed>, actor_kind:string, actor_name:?string, staff_only:bool}>
     */
    public static function rows( string $scenario ): array {
        $count = DrawerProfiles::activity_count_for( $scenario );
        $rows  = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $event_type = self::EVENT_TYPES[ $i % count( self::EVENT_TYPES ) ];

            $rows[] = [
                'id'          => $count - $i,
                'occurred_at' => Dates::offset( -$i * DAY_IN_SECONDS ),
                'event_type'  => $event_type,
                'data'        => self::data_for( $event_type ),
                'actor_kind'  => self::actor_kind_for( $event_type ),
                'actor_name'  => 'staff' === self::actor_kind_for( $event_type ) ? 'Sarah Chen' : null,
                'staff_only'  => 'points_adjusted' === $event_type || 'expiry_reset' === $event_type,
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function data_for( string $event_type ): array {
        switch ( $event_type ) {
            case 'points_adjusted':
                return [
                    'points' => 500,
                    'note'   => 'Late delivery — goodwill',
                ];
            case 'tier_changed':
                return [
                    'from_tier' => 'Explorer',
                    'to_tier'   => 'Summit',
                    'automatic' => true,
                ];
            case 'expiry_reset':
                return [
                    'from' => Dates::offset( -30 * DAY_IN_SECONDS ),
                    'to'   => Dates::offset( 335 * DAY_IN_SECONDS ),
                    'note' => 'Asked for more time by email',
                ];
            case 'reward_redeemed':
                return [
                    'reward'   => 'Free shipping',
                    'order_id' => 4098,
                ];
            case 'balance_email_sent':
                return [ 'subject' => 'Your points balance has been updated' ];
            case 'expiring_soon_email_sent':
                return [ 'window_days' => 30 ];
            case 'account_created':
            default:
                return [
                    'campaign' => 'Account signup campaign',
                    'points'   => 100,
                ];
        }
    }

    private static function actor_kind_for( string $event_type ): string {
        if ( in_array( $event_type, [ 'points_adjusted', 'expiry_reset' ], true ) ) {
            return 'staff';
        }

        if ( in_array( $event_type, [ 'balance_email_sent', 'expiring_soon_email_sent', 'tier_changed' ], true ) ) {
            return 'automatic';
        }

        return 'customer';
    }
}
