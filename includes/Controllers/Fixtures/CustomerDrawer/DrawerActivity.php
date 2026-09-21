<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Controllers\Schemas\ActivityQueryArgs;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Activity-log rows — structured, not sentences. Written in Wave A so the row
 * shape was decided while the design was in hand; wired to a real route and
 * UI in Wave B (`ActivityRestController`, `tabs/ActivityLogTab.tsx`).
 *
 * `customer-drawer.copy.ts` composes every sentence from `event_type` +
 * `data`, exactly as `dashboard-page.notice-copy.ts` does for dashboard
 * notices — never from a string stored here.
 */
final class DrawerActivity {

    /**
     * @return array<int, array{id:int, occurred_at:string, event_type:string, data:array<string,mixed>, actor_kind:string, actor_name:?string, staff_only:bool}>
     */
    public static function rows( string $scenario ): array {
        $count = DrawerProfiles::profile( $scenario )['counts']['activity'];
        $rows  = [];

        for ( $i = 0; $i < $count; $i++ ) {
            $event_type = ActivityQueryArgs::EVENT_TYPES[ $i % count( ActivityQueryArgs::EVENT_TYPES ) ];

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

    /**
     * `group`/`search`/date-filtered, paged envelope — what
     * `ActivityRestController` answers from under `?scenario=`. `total` is
     * the FILTERED count (what the pager's "1–10 of N" reads), unlike
     * `counts.activity` on the customer payload, which always stays the
     * unfiltered total the tab label shows.
     *
     * @param array{page:int,per_page:int,search?:string,after?:string,before?:string} $args
     * @return array{items: array, total: int, page: int, per_page: int}
     */
    public static function envelope( string $scenario, string $group, array $args ): array {
        return CustomerDrawerFixture::page( self::apply_filters( self::rows( $scenario ), $group, $args ), $args );
    }

    /**
     * @param array<int, array<string, mixed>>                                 $rows
     * @param array{search?:string,after?:string,before?:string}               $args
     * @return array<int, array<string, mixed>>
     */
    private static function apply_filters( array $rows, string $group, array $args ): array {
        $types  = ActivityQueryArgs::event_types_for( $group );
        $search = CustomerDrawerFixture::search_needle( $args );

        return array_values(
            array_filter(
                $rows,
                static function ( array $row ) use ( $types, $search, $args ): bool {
                    if ( null !== $types && ! in_array( $row['event_type'], $types, true ) ) {
                        return false;
                    }

                    if ( '' !== $search && ! self::matches_search( $row, $search ) ) {
                        return false;
                    }

                    return CustomerDrawerFixture::in_date_window( $row['occurred_at'], $args );
                }
            )
        );
    }

    /**
     * Search matches the actor's name plus every string OR int value inside
     * `data` — the same "whatever a human would recognise" scope
     * `DrawerLedger::apply_filters()` gives its own `note`/`order_id` search,
     * just over a row shape where the searchable text is nested rather than
     * top-level. `order_id` (`reward_redeemed`) is an int in `data`, same as
     * the ledger's own `order_id` field — included here for the same reason
     * the ledger's incidental string-concatenation cast includes it: an
     * admin searching an order number from a support ticket expects both
     * tabs to find it. Bools (`tier_changed`'s `automatic`) are deliberately
     * excluded — PHP stringifies `true`/`false` as `"1"`/`""`, which would
     * make a bare "1" search match every automatic-tier-change row.
     *
     * @param array<string, mixed> $row
     */
    private static function matches_search( array $row, string $search ): bool {
        $haystack = strtolower( (string) ( $row['actor_name'] ?? '' ) );

        foreach ( $row['data'] as $value ) {
            if ( is_string( $value ) || is_int( $value ) ) {
                $haystack .= ' ' . strtolower( (string) $value );
            }
        }

        return false !== strpos( $haystack, $search );
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
                    'threshold' => 5000,
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
