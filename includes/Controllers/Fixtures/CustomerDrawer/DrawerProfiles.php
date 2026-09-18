<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\PointsMath;

defined( 'ABSPATH' ) || exit;

/**
 * The six drawer scenarios' identities and header stats — see
 * `CustomerDrawerFixture` for the gate this feeds. Figures are taken
 * verbatim from the design's artboards 1e/1f/3a/3c/4a/4b/5a; `expiring`
 * reuses `''` (default)'s identity because the design draws it as the same
 * fictional person (Daniel Okonkwo) in a different state, not a new one.
 */
final class DrawerProfiles {

    /**
     * One row per scenario: [name, email, joined_at, balance, pending,
     * lifetime_earned, lifetime_redeemed, expires_at, deleted_at,
     * counts_ledger, counts_activity, counts_redemptions, counts_referrals].
     * `''` is the default (no `?scenario=`) artboard — Daniel Okonkwo.
     *
     * @return array<string, array{0:string,1:string,2:string,3:int,4:int,5:int,6:int,7:?string,8:?string,9:int,10:int,11:int,12:int}>
     */
    private static function rows(): array {
        return [
            ''         => [ 'Daniel Okonkwo', 'daniel.okonkwo@example.com', '2024-03-12 00:00:00', 9850, 240, 14300, 4450, '2027-08-14 00:00:00', null, 48, 24, 6, 3 ],
            'new'      => [ 'Lena Haugen', 'lena.haugen@example.com', '2026-09-09 00:00:00', 0, 0, 0, 0, null, null, 0, 0, 0, 0 ],
            'large'    => [ 'Aisha Bello', 'aisha.bello@example.com', '2023-11-03 00:00:00', 71340, 405, 138210, 66870, '2027-09-09 00:00:00', null, 4812, 1940, 38, 12 ],
            'deleted'  => [ 'Erik Halvorsen', 'erik.halvorsen@example.com', '2025-02-04 00:00:00', 1850, 0, 6420, 4570, null, '2026-09-08 00:00:00', 24, 6, 1, 2 ],
            // Same identity as '' — 4b is Daniel again, 12 days from losing
            // 6,300 of his 9,850 points, not a different fictional person.
            'expiring' => [ 'Daniel Okonkwo', 'daniel.okonkwo@example.com', '2024-03-12 00:00:00', 9850, 240, 14300, 4450, '2027-08-14 00:00:00', null, 48, 24, 6, 3 ],
            // 3 ledger rows, not a round number — `DrawerNegativeBalance::ledger_rows()`
            // tells 5a's exact story (earn 1,000, redeem it, refund reverses
            // it), and this count must match how
            // many rows that method actually returns.
            'negative' => [ 'Tom Weaver', 'tom.weaver@example.com', '2024-11-11 00:00:00', -1000, 0, 4260, 1000, null, null, 3, 2, 1, 0 ],
        ];
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
     * The rate every scenario values
     * built-in `cart-discount` default, so Aisha's 71,340 → $713.40 lands on
     * the exact figure artboard 3c quotes.
     */
    private const POINTS_PER_CURRENCY_UNIT = 100;

    public static function to_wire( string $scenario, int $user_id ): array {
        $row = self::rows()[ $scenario ] ?? self::rows()[''];

        [ $name, $email, $joined_at, $balance, $pending, $lifetime_earned, $lifetime_redeemed, $expires_at, $deleted_at, $ledger, $activity, $redemptions, $referrals ] = $row;

        return [
            'user_id'                  => $user_id,
            'name'                     => $name,
            'email'                    => $email,
            'balance'                  => $balance,
            'pending_points'           => $pending,
            'lifetime_earned'          => $lifetime_earned,
            'lifetime_redeemed'        => $lifetime_redeemed,
            'last_activity_at'         => 0 === $ledger ? null : Dates::offset( -DAY_IN_SECONDS ),
            'expires_at'               => $expires_at,
            'joined_at'                => $joined_at,
            'updated_at'               => Dates::now(),
            'deleted_at'               => $deleted_at,
            'counts'                   => [
                'ledger'      => $ledger,
                'activity'    => $activity,
                'redemptions' => $redemptions,
                'referrals'   => $referrals,
            ],
            'loose_ends'               => self::loose_ends_for( $scenario ),
            'expiry'                   => DrawerExpiry::for_scenario( $scenario, $balance ),
            'negative_balance'         => DrawerNegativeBalance::for_scenario( $scenario, $balance ),
            // Restored minimally (F09 Wave B phase 03) after the `worth`
            // object was removed wholesale — 4b/5a's money-equivalent
            // figures need the rate, `worth`'s display no longer does.
            'points_per_currency_unit' => self::POINTS_PER_CURRENCY_UNIT,
        ];
    }

    /** The scenario's ending balance — what `DrawerLedger` walks its chain backward from. */
    public static function balance_for( string $scenario ): int {
        return ( self::rows()[ $scenario ] ?? self::rows()[''] )[3];
    }

    /** The scenario's ledger row count — `DrawerLedger::rows()` generates exactly this many. */
    public static function ledger_count_for( string $scenario ): int {
        return ( self::rows()[ $scenario ] ?? self::rows()[''] )[9];
    }

    /** The scenario's activity row count — `DrawerActivity::rows()` generates exactly this many. */
    public static function activity_count_for( string $scenario ): int {
        return ( self::rows()[ $scenario ] ?? self::rows()[''] )[10];
    }
}
