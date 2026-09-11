<?php
namespace YayLoyalty\Controllers;

defined( 'ABSPATH' ) || exit;

/**
 * TEMPORARY DEMO DATA — NOT A REAL QUERY.
 *
 * Backs `CustomerRestController::get_items()` (GET /yayloyalty/v1/customers).
 * `CustomerRepository` has no list/paginate method yet, so there is no real
 * Customers list to serve — this is the same fixed six rows every time,
 * shaped to match `apps/admin/src/pages/customers/customers-page.mock-data.ts`'s
 * `MockCustomer`.
 *
 * Split into its own file (rather than a private method on the controller)
 * to keep both files under the project's 200-line file cap — it is one
 * table literal, not behaviour.
 *
 * DELETE this whole class, and the route/method that call it, once
 * `CustomerRepository` gains a real `paginate_filtered()`-style method.
 */
final class CustomerDemoRows {

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array {
        return [
            [
                'user_id'                   => 1001,
                'name'                      => 'Marta Bergström',
                'email'                     => 'marta.bergstrom@example.com',
                'balance'                   => 12400,
                'pending_points'            => 0,
                'lifetime_earned'           => 18900,
                'last_activity_label'       => '2 days ago',
                'last_activity_minutes_ago' => 2 * 24 * 60,
                'expires_at'                => '2028-03-12 00:00:00',
                'tier'                      => 'Alpine',
                'referrals'                 => 6,
            ],
            [
                'user_id'                   => 1002,
                'name'                      => 'Daniel Okonkwo',
                'email'                     => 'daniel.okonkwo@example.com',
                'balance'                   => 9850,
                'pending_points'            => 240,
                'lifetime_earned'           => 14300,
                'last_activity_label'       => '12 min ago',
                'last_activity_minutes_ago' => 12,
                'expires_at'                => '2027-08-14 00:00:00',
                'tier'                      => 'Summit',
                'referrals'                 => 3,
            ],
            [
                'user_id'                   => 1003,
                'name'                      => 'Priya Raghunathan',
                'email'                     => 'priya.raghunathan@example.com',
                'balance'                   => 8200,
                'pending_points'            => 0,
                'lifetime_earned'           => 11750,
                'last_activity_label'       => '47 min ago',
                'last_activity_minutes_ago' => 47,
                'expires_at'                => '2028-02-03 00:00:00',
                'tier'                      => 'Summit',
                'referrals'                 => 1,
            ],
            [
                'user_id'                   => 1004,
                'name'                      => 'Yuki Tanaka',
                'email'                     => 'yuki.tanaka@example.com',
                'balance'                   => 6540,
                'pending_points'            => 50,
                'lifetime_earned'           => 9180,
                'last_activity_label'       => '1 hour ago',
                'last_activity_minutes_ago' => 60,
                'expires_at'                => '2027-11-21 00:00:00',
                'tier'                      => 'Summit',
                'referrals'                 => 0,
            ],
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
        ];
    }
}
