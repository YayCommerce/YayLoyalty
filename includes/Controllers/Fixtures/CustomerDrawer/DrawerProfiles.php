<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * The drawer scenarios' identities and header stats — see
 * `CustomerDrawerFixture` for the gate this feeds. Figures are taken
 * verbatim from the design's artboards 1e/1f/3a/3c/4a/4b/5a.
 */
final class DrawerProfiles {

    /**
     * One profile per identity. `''` is the default (no `?scenario=`)
     * artboard — Daniel Okonkwo. `expiring` has no row of its own: it is
     * Daniel in a different state (see `identity_of()`), and only `expiry`
     * differs, which `DrawerExpiry` keys on the raw scenario.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function rows(): array {
        return [
            ''         => [
                'name'              => 'Daniel Okonkwo',
                'email'             => 'daniel.okonkwo@example.com',
                'joined_at'         => '2024-03-12 00:00:00',
                'balance'           => 9850,
                'pending'           => 240,
                'lifetime_earned'   => 14300,
                'lifetime_redeemed' => 4450,
                'expires_at'        => '2027-08-14 00:00:00',
                'deleted_at'        => null,
                'counts'            => [
                    'ledger'      => 48,
                    'activity'    => 24,
                    'redemptions' => 6,
                    'referrals'   => 3,
                ],
            ],
            'new'      => [
                'name'              => 'Lena Haugen',
                'email'             => 'lena.haugen@example.com',
                'joined_at'         => '2026-09-09 00:00:00',
                'balance'           => 0,
                'pending'           => 0,
                'lifetime_earned'   => 0,
                'lifetime_redeemed' => 0,
                'expires_at'        => null,
                'deleted_at'        => null,
                'counts'            => [
                    'ledger'      => 0,
                    'activity'    => 0,
                    'redemptions' => 0,
                    'referrals'   => 0,
                ],
            ],
            'large'    => [
                'name'              => 'Aisha Bello',
                'email'             => 'aisha.bello@example.com',
                'joined_at'         => '2023-11-03 00:00:00',
                'balance'           => 71340,
                'pending'           => 405,
                'lifetime_earned'   => 138210,
                'lifetime_redeemed' => 66870,
                'expires_at'        => '2027-09-09 00:00:00',
                'deleted_at'        => null,
                'counts'            => [
                    'ledger'      => 4812,
                    'activity'    => 1940,
                    'redemptions' => 38,
                    'referrals'   => 12,
                ],
            ],
            'deleted'  => [
                'name'              => 'Erik Halvorsen',
                'email'             => 'erik.halvorsen@example.com',
                'joined_at'         => '2025-02-04 00:00:00',
                'balance'           => 1850,
                'pending'           => 0,
                'lifetime_earned'   => 6420,
                'lifetime_redeemed' => 4570,
                'expires_at'        => null,
                'deleted_at'        => '2026-09-08 00:00:00',
                'counts'            => [
                    'ledger'      => 24,
                    'activity'    => 6,
                    'redemptions' => 1,
                    'referrals'   => 2,
                ],
            ],
            'negative' => [
                'name'              => 'Tom Weaver',
                'email'             => 'tom.weaver@example.com',
                'joined_at'         => '2024-11-11 00:00:00',
                'balance'           => -1000,
                'pending'           => 0,
                'lifetime_earned'   => 4260,
                'lifetime_redeemed' => 1000,
                'expires_at'        => null,
                'deleted_at'        => null,
                'counts'            => [
                    // The ledger tells 5a's exact story, so its rows are the count.
                    'ledger'      => count( DrawerNegativeBalance::ledger_rows( 0 ) ),
                    'activity'    => 2,
                    'redemptions' => 1,
                    'referrals'   => 0,
                ],
            ],
        ];
    }

    /** The profile a scenario renders — `expiring` is Daniel (`''`); unknown scenarios fall back to him too. */
    public static function identity_of( string $scenario ): string {
        if ( 'expiring' === $scenario || ! isset( self::rows()[ $scenario ] ) ) {
            return '';
        }

        return $scenario;
    }

    /** @return array<string, mixed> */
    public static function profile( string $scenario ): array {
        return self::rows()[ self::identity_of( $scenario ) ];
    }

    /**
     * 4a's "Loose ends this leaves" facts — only the `deleted` scenario has
     * any (every other scenario reads `null` via `loose_ends_for()`).
     * Structured only: "1 · $5 off" is composed client-side from
     * `count`+`example`, never stored as a sentence here.
     *
     * @var array<string, array{void_coupons: array{count:int,example:?string}, credited_referrals:int, counted_in_members:bool, kept_ledger_entries:int}>
     */
    private const LOOSE_ENDS = [
        'deleted' => [
            'void_coupons'        => [
                'count'   => 1,
                'example' => '$5 off',
            ],
            'credited_referrals'  => 2,
            'counted_in_members'  => false,
            'kept_ledger_entries' => 24,
        ],
    ];

    /** @return array{void_coupons: array{count:int,example:?string}, credited_referrals:int, counted_in_members:bool, kept_ledger_entries:int}|null */
    public static function loose_ends_for( string $scenario ): ?array {
        return self::LOOSE_ENDS[ $scenario ] ?? null;
    }

    /**
     * The rate every scenario values points at — the built-in `cart-discount`
     * reward's default, so Aisha's 71,340 → $713.40 lands on the exact figure
     * artboard 3c quotes.
     */
    private const POINTS_PER_CURRENCY_UNIT = 100;

    public static function to_wire( string $scenario, int $user_id ): array {
        $row = self::profile( $scenario );

        return [
            'user_id'                  => $user_id,
            'name'                     => $row['name'],
            'email'                    => $row['email'],
            'balance'                  => $row['balance'],
            'pending_points'           => $row['pending'],
            'lifetime_earned'          => $row['lifetime_earned'],
            'lifetime_redeemed'        => $row['lifetime_redeemed'],
            'last_activity_at'         => 0 === $row['counts']['ledger'] ? null : Dates::offset( -DAY_IN_SECONDS ),
            'expires_at'               => $row['expires_at'],
            'joined_at'                => $row['joined_at'],
            'updated_at'               => Dates::now(),
            'deleted_at'               => $row['deleted_at'],
            'counts'                   => $row['counts'],
            'loose_ends'               => self::loose_ends_for( $scenario ),
            'expiry'                   => DrawerExpiry::for_scenario( $scenario ),
            'negative_balance'         => DrawerNegativeBalance::for_scenario( $scenario ),
            'points_per_currency_unit' => self::POINTS_PER_CURRENCY_UNIT,
        ];
    }
}
