<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/** TEMPORARY DEMO DATA — see `CustomerDemoRows`. The Explorer-tier rows. */
final class CustomerDemoRowsExplorer {

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array {
        return [
            [
                'user_id'                   => 1005,
                'name'                      => 'Ana Sousa',
                'email'                     => 'ana.sousa@example.com',
                'balance'                   => 340,
                'pending_points'            => 100,
                'lifetime_earned'           => 340,
                'last_activity_label'       => '3 hours ago',
                'last_activity_minutes_ago' => 3 * 60,
                'expires_at'                => '2027-09-09 00:00:00',
                'tier'                      => 'Explorer',
                'referrals'                 => 2,
            ],
            [
                // Negative balance: a refund clawed back more than Tom held.
                // No expiry while balance is negative — see BalanceCell.tsx.
                'user_id'                   => 1006,
                'name'                      => 'Tom Weaver',
                'email'                     => 'tom.weaver@example.com',
                'balance'                   => -1000,
                'pending_points'            => 0,
                'lifetime_earned'           => 4260,
                'last_activity_label'       => '2 hours ago',
                'last_activity_minutes_ago' => 2 * 60,
                'expires_at'                => null,
                'tier'                      => 'Explorer',
                'referrals'                 => 0,
            ],
            [
                'user_id'                   => 1017,
                'name'                      => 'Amara Okafor',
                'email'                     => 'amara.okafor@example.com',
                'balance'                   => 890,
                'pending_points'            => 0,
                'lifetime_earned'           => 1200,
                'last_activity_label'       => '3 hours ago',
                'last_activity_minutes_ago' => 3 * 60,
                'expires_at'                => '2027-04-09 00:00:00',
                'tier'                      => 'Explorer',
                'referrals'                 => 0,
            ],
            [
                // Expires within 31-90 days.
                'user_id'                   => 1018,
                'name'                      => 'Ravi Menon',
                'email'                     => 'ravi.menon@example.com',
                'balance'                   => 720,
                'pending_points'            => 80,
                'lifetime_earned'           => 900,
                'last_activity_label'       => '8 min ago',
                'last_activity_minutes_ago' => 8,
                'expires_at'                => '2026-12-05 00:00:00',
                'tier'                      => 'Explorer',
                'referrals'                 => 1,
            ],
            [
                'user_id'                   => 1019,
                'name'                      => 'Ingrid Larsen',
                'email'                     => 'ingrid.larsen@example.com',
                'balance'                   => 150,
                'pending_points'            => 0,
                'lifetime_earned'           => 150,
                'last_activity_label'       => '1 hour ago',
                'last_activity_minutes_ago' => 60,
                'expires_at'                => '2027-01-01 00:00:00',
                'tier'                      => 'Explorer',
                'referrals'                 => 0,
            ],
            [
                // Balance 0 exactly — the 0–1,000 bucket's lower boundary.
                // No expiry, and NOT because of a negative balance.
                'user_id'                   => 1020,
                'name'                      => 'Diego Fernandez',
                'email'                     => 'diego.fernandez@example.com',
                'balance'                   => 0,
                'pending_points'            => 200,
                'lifetime_earned'           => 0,
                'last_activity_label'       => '10 min ago',
                'last_activity_minutes_ago' => 10,
                'expires_at'                => null,
                'tier'                      => 'Explorer',
                'referrals'                 => 0,
            ],
            [
                // Second negative-balance row, distinct amount from Tom's.
                'user_id'                   => 1021,
                'name'                      => 'Grace Kim',
                'email'                     => 'grace.kim@example.com',
                'balance'                   => -450,
                'pending_points'            => 0,
                'lifetime_earned'           => 620,
                'last_activity_label'       => '5 hours ago',
                'last_activity_minutes_ago' => 5 * 60,
                'expires_at'                => null,
                'tier'                      => 'Explorer',
                'referrals'                 => 0,
            ],
            [
                // Balance exactly 1,000 — the 0–1,000 bucket's upper boundary
                // (inclusive; see matchesBalanceRange() in filter-rows.ts).
                'user_id'                   => 1025,
                'name'                      => 'Zara Ahmed',
                'email'                     => 'zara.ahmed@example.com',
                'balance'                   => 1000,
                'pending_points'            => 0,
                'lifetime_earned'           => 1000,
                'last_activity_label'       => '2 hours ago',
                'last_activity_minutes_ago' => 2 * 60,
                'expires_at'                => '2027-06-06 00:00:00',
                'tier'                      => 'Explorer',
                'referrals'                 => 0,
            ],
            [
                'user_id'                   => 1029,
                'name'                      => 'Sakura Ito',
                'email'                     => 'sakura.ito@example.com',
                'balance'                   => 480,
                'pending_points'            => 0,
                'lifetime_earned'           => 480,
                'last_activity_label'       => '10 hours ago',
                'last_activity_minutes_ago' => 10 * 60,
                'expires_at'                => '2027-05-25 00:00:00',
                'tier'                      => 'Explorer',
                'referrals'                 => 0,
            ],
        ];
    }
}
