<?php
namespace YayLoyalty\Controllers\Fixtures\CustomerDrawer;

use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * 4b's expiring-batch summary + Pro FIFO lot table (F09 Wave B). Split out of
 * `DrawerProfiles` to keep that class near the 200-line cap, the same reason
 * `DrawerActivity`/`DrawerLedger` are their own files.
 *
 * This INVENTS the `lots` wire contract — no `yayloyalty_lots` table exists
 * yet (docs/data-model.md). Kept deliberately minimal: four fields per batch,
 * nothing speculative. Note for whoever builds the real table: `lots` itself
 * has no "earned range" column (one row per `earn` event, just `expires_at`)
 * — the range shown here is a DISPLAY-level bucketing of lots that share an
 * expiry window, not a literal DB column. The real endpoint will need to
 * group lots by that window, not select this shape directly.
 *
 * Only the `expiring` scenario has a batch; every other scenario (including
 * negative and deleted, where expiry has stopped or is moot) returns `null`.
 */
final class DrawerExpiry {

    /**
     * Batch data for the `expiring` scenario — Daniel Okonkwo's 9,850 points
     * across three earn windows, one of which (6,300) is 4b's expiring lot.
     * Points sum to his balance (`DrawerProfiles::rows()`'s `expiring` row)
     * by construction: 6300 + 2180 + 1370 = 9850.
     *
     * @return array<int, array{earned_from:string, earned_to:string, points:int, expires_at:string}>
     */
    private static function batches(): array {
        return [
            [
                'earned_from' => '2024-09-01',
                'earned_to'   => '2025-03-01',
                'points'      => 6300,
                'expires_at'  => '2027-08-14',
            ],
            [
                'earned_from' => '2025-04-01',
                'earned_to'   => '2025-12-01',
                'points'      => 2180,
                'expires_at'  => '2028-02-03',
            ],
            [
                'earned_from' => '2026-01-01',
                'earned_to'   => '2026-09-01',
                'points'      => 1370,
                'expires_at'  => '2028-09-09',
            ],
        ];
    }

    /**
     * @return array{expiring_points:int, expires_at:string, safe_points:int, reminders:array{sent_at:?string,next_due_at:?string,window_days:int}, batches:?array}|null
     */
    public static function for_scenario( string $scenario, int $balance ): ?array {
        if ( 'expiring' !== $scenario ) {
            return null;
        }

        $batches         = self::batches();
        $expiring_batch  = $batches[0];
        $expiring_points = $expiring_batch['points'];
        $expires_at      = $expiring_batch['expires_at'] . ' 00:00:00';

        return [
            'expiring_points' => $expiring_points,
            'expires_at'      => $expires_at,
            'safe_points'     => $balance - $expiring_points,
            'reminders'       => [
                // 30-day reminder already sent; the 7-day one is upcoming —
                // both anchored to `expires_at`, not "now", so they stay
                // correct regardless of when the fixture is viewed.
                'sent_at'     => Dates::offset( -30 * DAY_IN_SECONDS, $expires_at ),
                'next_due_at' => Dates::offset( -7 * DAY_IN_SECONDS, $expires_at ),
                'window_days' => 7,
            ],
            'batches'         => $batches,
        ];
    }
}
