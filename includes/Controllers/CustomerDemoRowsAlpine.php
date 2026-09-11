<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/** TEMPORARY DEMO DATA — see `CustomerDemoRows`. The Alpine-tier rows. */
final class CustomerDemoRowsAlpine {

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array {
        return [
            [
                'user_id'                   => 1001,
                'name'                      => 'Marta Bergström',
                'email'                     => 'marta.bergstrom@example.com',
                'balance'                   => 124000,
                'pending_points'            => 0,
                'lifetime_earned'           => 18900,
                'last_activity_label'       => '2 days ago',
                'last_activity_minutes_ago' => 2 * 24 * 60,
                'expires_at'                => '2028-03-12 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 6,
            ],
            [
                'user_id'                   => 1007,
                'name'                      => 'Hassan Al-Farsi',
                'email'                     => 'hassan.alfarsi@example.com',
                'balance'                   => 15600,
                'pending_points'            => 0,
                'lifetime_earned'           => 24100,
                'last_activity_label'       => '5 min ago',
                'last_activity_minutes_ago' => 5,
                'expires_at'                => '2028-01-15 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 9,
            ],
            [
                // Expires within 30 days of "today" — exercises the
                // Expiring soon filter for real (see filter-rows.ts).
                'user_id'                   => 1008,
                'name'                      => 'Chen Wei',
                'email'                     => 'chen.wei@example.com',
                'balance'                   => 22000,
                'pending_points'            => 500,
                'lifetime_earned'           => 31000,
                'last_activity_label'       => '18 min ago',
                'last_activity_minutes_ago' => 18,
                'expires_at'                => '2026-09-20 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 14,
            ],
            [
                'user_id'                   => 1009,
                'name'                      => 'Sofia Almeida',
                'email'                     => 'sofia.almeida@example.com',
                'balance'                   => 11200,
                'pending_points'            => 0,
                'lifetime_earned'           => 15800,
                'last_activity_label'       => '2 hours ago',
                'last_activity_minutes_ago' => 2 * 60,
                'expires_at'                => '2027-05-10 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 3,
            ],
            [
                // Expires within 30 days — second row for that bucket.
                'user_id'                   => 1010,
                'name'                      => 'Kwame Mensah',
                'email'                     => 'kwame.mensah@example.com',
                'balance'                   => 18750,
                'pending_points'            => 1200,
                'lifetime_earned'           => 26400,
                'last_activity_label'       => '9 min ago',
                'last_activity_minutes_ago' => 9,
                'expires_at'                => '2026-10-01 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 20,
            ],
            [
                // Balance 10,001 — one past the 10,000+ bucket boundary.
                'user_id'                   => 1024,
                'name'                      => 'Emeka Chukwu',
                'email'                     => 'emeka.chukwu@example.com',
                'balance'                   => 10001,
                'pending_points'            => 0,
                'lifetime_earned'           => 15200,
                'last_activity_label'       => '20 min ago',
                'last_activity_minutes_ago' => 20,
                'expires_at'                => '2027-08-30 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 7,
            ],
            [
                'user_id'                   => 1026,
                'name'                      => 'Viktor Petrov',
                'email'                     => 'viktor.petrov@example.com',
                'balance'                   => 13400,
                'pending_points'            => 600,
                'lifetime_earned'           => 19800,
                'last_activity_label'       => '4 min ago',
                'last_activity_minutes_ago' => 4,
                'expires_at'                => '2028-04-11 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 11,
            ],
            [
                // Expires within 30 days — third row for that bucket.
                'user_id'                   => 1030,
                'name'                      => 'Benedikt Fischer',
                'email'                     => 'benedikt.fischer@example.com',
                'balance'                   => 16200,
                'pending_points'            => 0,
                'lifetime_earned'           => 21000,
                'last_activity_label'       => '30 min ago',
                'last_activity_minutes_ago' => 30,
                'expires_at'                => '2026-09-25 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 5,
            ],
        ];
    }
}
