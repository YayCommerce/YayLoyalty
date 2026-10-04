<?php
namespace YayLoyalty\Database;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * One-time starter data: five real WooCommerce customers with a ledger
 * history through LedgerService::adjust(), so balance = SUM(ledger.points)
 * holds. Runs exactly once: the option flag is claimed with add_option()
 * BEFORE any write (the opposite of the version stamp), because a retry
 * after a mid-loop crash would create a second batch of users. One of the
 * construction sites LedgerService's docblock lists.
 */
final class InitYayLoyaltyCustomer {

    public const OPTION_INITIALIZED = 'yayloyalty_demo_customers_initialized';

    /** build_row() must return keys in this order. */
    private const ROW_FORMAT = [
        'pending_points'   => '%d',
        // Money is bound as %s, never %f, which rounds through a float.
        'lifetime_spend'   => '%s',
        'last_activity_at' => '%s',
        'expires_at'       => '%s',
        'reminder_sent_at' => '%s',
        'referral_code'    => '%s',
        'birthday_month'   => '%d',
        'birthday_day'     => '%d',
        'joined_at'        => '%s',
    ];

    public static function maybe_create(): void {
        if ( ! function_exists( 'wc_create_new_customer' ) ) {
            return;
        }

        /**
         * Whether to create the starter customers on install.
         *
         * @param bool $create
         */
        if ( ! apply_filters( 'yayloyalty_init_demo_customers', true ) ) {
            return;
        }

        if ( ! add_option( self::OPTION_INITIALIZED, Dates::now(), '', 'no' ) ) {
            return; // Already claimed — by this request or an earlier one.
        }

        $customers      = new CustomerRepository();
        $ledger_service = new LedgerService( new LedgerRepository(), $customers );

        // A "new account" email from a plugin install would be surprising.
        add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );

        foreach ( self::customer_data() as $data ) {
            self::create_one( $customers, $ledger_service, $data );
        }

        remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
    }

    private static function create_one( CustomerRepository $customers, LedgerService $ledger_service, array $data ): void {
        // Returns WP_Error on an existing email, so a real customer is never adopted.
        $user_id = wc_create_new_customer(
            $data['email'],
            $data['username'],
            wp_generate_password(),
            [
                'first_name'   => $data['first_name'],
                'last_name'    => $data['last_name'],
                'display_name' => $data['first_name'] . ' ' . $data['last_name'],
            ]
        );

        if ( is_wp_error( $user_id ) ) {
            Log::warning( 'InitYayLoyaltyCustomer', 'skipped ' . $data['email'] . ': ' . $user_id->get_error_message() );

            return;
        }

        // One failed customer logs and the loop moves on: the flag is already claimed,
        // so an escaping exception would skip every later customer forever.
        try {
            // actor_id 0 → NULL: not attributed to any admin.
            foreach ( $data['ledger_entries'] as [ $points, $note ] ) {
                $ledger_service->adjust( $user_id, $points, $note, 0 );
            }

            $customers->update( $user_id, self::build_row( $user_id, $data ), array_values( self::ROW_FORMAT ) );
        } catch ( \Throwable $exception ) {
            Log::error( 'InitYayLoyaltyCustomer', 'failed to write customer data for user ' . $user_id . ': ' . $exception->getMessage() );
        }
    }

    /**
     * The columns LedgerService::adjust() never touches (it sets only balance and lifetime_earned).
     *
     * @return array<string, mixed> Keys match ROW_FORMAT, same order.
     */
    private static function build_row( int $user_id, array $data ): array {
        return [
            'pending_points'   => $data['pending_points'],
            'lifetime_spend'   => $data['lifetime_spend'],
            'last_activity_at' => Dates::offset( $data['last_activity_offset'] ),
            'expires_at'       => null === $data['expires_offset'] ? null : Dates::offset( $data['expires_offset'] ),
            'reminder_sent_at' => null === $data['reminder_offset'] ? null : Dates::offset( $data['reminder_offset'] ),
            'referral_code'    => 'YAY' . $user_id,
            'birthday_month'   => $data['birthday'][0] ?? null,
            'birthday_day'     => $data['birthday'][1] ?? null,
            'joined_at'        => Dates::offset( $data['joined_offset'] ),
        ];
    }

    /**
     * Five people, one per balance/expiry filter combination. Date offsets
     * are relative so the buckets hold a month after install. `ledger_entries`
     * are [points, note] pairs; a negative second entry yields a real
     * negative balance with an earn behind it.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function customer_data(): array {
        return [
            [
                'first_name'           => 'Marta',
                'last_name'            => 'Bergström',
                'username'             => 'marta.bergstrom',
                'email'                => 'marta.bergstrom@example.com',
                'ledger_entries'       => [ [ 1400, 'Migrated historical balance' ], [ -1650, 'Refund clawback adjustment' ] ],
                'pending_points'       => 0,
                'lifetime_spend'       => '210.5000',
                'last_activity_offset' => -2 * DAY_IN_SECONDS,
                'expires_offset'       => null, // "No expiry date" bucket.
                'reminder_offset'      => null,
                'joined_offset'        => -400 * DAY_IN_SECONDS,
                'birthday'             => null,
            ],
            [
                'first_name'           => 'Hassan',
                'last_name'            => 'Al-Farsi',
                'username'             => 'hassan.alfarsi',
                'email'                => 'hassan.alfarsi@example.com',
                'ledger_entries'       => [ [ 980, 'Migrated historical balance' ], [ -340, 'Goodwill correction' ] ],
                'pending_points'       => 120,
                'lifetime_spend'       => '78.9000',
                'last_activity_offset' => -30 * MINUTE_IN_SECONDS,
                'expires_offset'       => 20 * DAY_IN_SECONDS, // "Within 30 days" bucket.
                'reminder_offset'      => -1 * DAY_IN_SECONDS,
                'joined_offset'        => -60 * DAY_IN_SECONDS,
                'birthday'             => [ 4, 12 ],
            ],
            [
                'first_name'           => 'Yuki',
                'last_name'            => 'Tanaka',
                'username'             => 'yuki.tanaka',
                'email'                => 'yuki.tanaka@example.com',
                'ledger_entries'       => [ [ 6100, 'Migrated historical balance' ], [ -1280, 'Manual correction' ] ],
                'pending_points'       => 0,
                'lifetime_spend'       => '612.2500',
                'last_activity_offset' => -5 * DAY_IN_SECONDS,
                'expires_offset'       => 75 * DAY_IN_SECONDS, // "Within 90 days" bucket.
                'reminder_offset'      => null,
                'joined_offset'        => -220 * DAY_IN_SECONDS,
                'birthday'             => [ 11, 3 ],
            ],
            [
                'first_name'           => 'Elena',
                'last_name'            => 'Popescu',
                'username'             => 'elena.popescu',
                'email'                => 'elena.popescu@example.com',
                'ledger_entries'       => [ [ 24900, 'Migrated historical balance' ], [ -6500, 'Manual correction' ] ],
                'pending_points'       => 250,
                'lifetime_spend'       => '2480.0000',
                'last_activity_offset' => -1 * DAY_IN_SECONDS,
                'expires_offset'       => 540 * DAY_IN_SECONDS, // Far-future expiry.
                'reminder_offset'      => null,
                'joined_offset'        => -730 * DAY_IN_SECONDS,
                'birthday'             => null,
            ],
            [
                'first_name'           => 'Noah',
                'last_name'            => 'Whitfield',
                'username'             => 'noah.whitfield',
                'email'                => 'noah.whitfield@example.com',
                'ledger_entries'       => [ [ 15300, 'Migrated historical balance' ], [ -3250, 'Manual correction' ] ],
                'pending_points'       => 0,
                'lifetime_spend'       => '1905.7500',
                'last_activity_offset' => -140 * DAY_IN_SECONDS,
                'expires_offset'       => -10 * DAY_IN_SECONDS, // Already expired.
                'reminder_offset'      => -45 * DAY_IN_SECONDS,
                'joined_offset'        => -500 * DAY_IN_SECONDS,
                'birthday'             => [ 7, 28 ],
            ],
        ];
    }
}
