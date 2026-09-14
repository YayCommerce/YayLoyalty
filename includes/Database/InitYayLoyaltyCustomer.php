<?php
namespace YayLoyalty\Database;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * One-time starter data: creates 5 real WooCommerce customers plus their
 * yayloyalty_customers rows, so a fresh install's admin Customers list is
 * never empty. See plans/260914-1547-customers-list-real-data/phase-01-*.md.
 *
 * Split out of Installer.php to keep that file from growing further (already
 * past the project's 200-line guideline) — this is also a distinct concern:
 * "create starter data" vs. "create/verify tables".
 *
 * RUNS EXACTLY ONCE, EVER. Called from Installer::install(), which is not
 * WooCommerce-gated, so `wc_create_new_customer()` may not exist yet — that
 * guard runs first and simply retries on the next install() call (activation,
 * upgrade, or a new network blog) once WooCommerce is active.
 *
 * IDEMPOTENCY: the option flag is claimed with add_option() BEFORE any write
 * happens, not stamped after — the opposite of Installer::OPTION_DB_VERSION.
 * A version stamp must retry after a crash; this must never retry, or a crash
 * mid-loop would create a second batch of users on the next request.
 * add_option() returning false is the atomic "already claimed" signal (same
 * trick Installer::acquire_lock() uses).
 *
 * BALANCE IS DELIBERATELY 0 ON EVERY ROW. `customers.balance =
 * SUM(ledger.points)` is a plugin-wide invariant (see docs/system-architecture.md);
 * writing a non-zero balance here with no matching ledger rows would break it
 * silently. Zero rows sum to zero, so the invariant holds without a single
 * ledger write. The other aggregate columns still vary per row so the list's
 * other columns and the expiring-soon filter stay exercised — only the
 * balance-range filter's "0-1000" bucket has a seeded example.
 */
final class InitYayLoyaltyCustomer {

    public const OPTION_INITIALIZED = 'yayloyalty_demo_customers_initialized';

    /** Column order both build_row() and $format below must agree on. */
    private const ROW_FORMAT = [
        'balance'           => '%d',
        'pending_points'    => '%d',
        'lifetime_earned'   => '%d',
        'lifetime_redeemed' => '%d',
        // Money: bound as %s, never %f — see code-standards.md section 5.
        'lifetime_spend'    => '%s',
        'last_activity_at'  => '%s',
        'expires_at'        => '%s',
        'reminder_sent_at'  => '%s',
        'referral_code'     => '%s',
        'birthday_month'    => '%d',
        'birthday_day'      => '%d',
        'joined_at'         => '%s',
    ];

    public static function maybe_create(): void {
        if ( ! function_exists( 'wc_create_new_customer' ) ) {
            return;
        }

        /**
         * Whether to create the 5 starter customers on install.
         *
         * @param bool $create
         */
        if ( ! apply_filters( 'yayloyalty_init_demo_customers', true ) ) {
            return;
        }

        if ( ! add_option( self::OPTION_INITIALIZED, Dates::now(), '', 'no' ) ) {
            return; // Already claimed — by this request or an earlier one.
        }

        $customers = new CustomerRepository();

        // Starter accounts are throwaway data; a real "new account" email from
        // a plugin install would be surprising.
        add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );

        foreach ( self::customer_data() as $data ) {
            self::create_one( $customers, $data );
        }

        remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
    }

    private static function create_one( CustomerRepository $customers, array $data ): void {
        // wc_create_new_customer() already checks email_exists() itself and
        // returns a WP_Error — no need to duplicate that check here. This
        // also means an existing account (a real customer, or a prior
        // partial run) is never adopted or overwritten.
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

        // Caught, not left to escape: rows are written independently (no shared
        // transaction — see class docblock), and this runs inside
        // Installer::install(), which plugins_loaded calls on every request
        // until it completes. An uncaught QueryException here would fatal
        // whichever page load hits it, and — because OPTION_INITIALIZED is
        // already claimed — silently skip every customer after the failed one
        // on every later request too. One failed row logs and the loop moves
        // on; the WP user account it already created (if any) is left as-is
        // rather than rolled back.
        try {
            $customers->ensure_row( $user_id );
            $customers->update( $user_id, self::build_row( $user_id, $data ), array_values( self::ROW_FORMAT ) );
        } catch ( QueryException $exception ) {
            Log::error( 'InitYayLoyaltyCustomer', 'failed to write customer row for user ' . $user_id . ': ' . $exception->getMessage() );
        }
    }

    /**
     * @return array<string, mixed> Keys match ROW_FORMAT, same order.
     */
    private static function build_row( int $user_id, array $data ): array {
        return [
            'balance'           => 0,
            'pending_points'    => $data['pending_points'],
            'lifetime_earned'   => $data['lifetime_earned'],
            'lifetime_redeemed' => $data['lifetime_redeemed'],
            'lifetime_spend'    => $data['lifetime_spend'],
            'last_activity_at'  => Dates::offset( $data['last_activity_offset'] ),
            'expires_at'        => null === $data['expires_offset'] ? null : Dates::offset( $data['expires_offset'] ),
            'reminder_sent_at'  => null === $data['reminder_offset'] ? null : Dates::offset( $data['reminder_offset'] ),
            // Structurally unique against the UNIQUE KEY — no collision-retry needed.
            'referral_code'     => 'YAY' . $user_id,
            'birthday_month'    => $data['birthday'][0] ?? null,
            'birthday_day'      => $data['birthday'][1] ?? null,
            'joined_at'         => Dates::offset( $data['joined_offset'] ),
        ];
    }

    /**
     * Pure data: 5 people, one per balance/expiry combination the admin list's
     * filters offer. Offsets are relative (Dates::offset()) so "within 30
     * days" is still within 30 days a month after install — never hardcoded.
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
                'pending_points'       => 0,
                'lifetime_earned'      => 1400,
                'lifetime_redeemed'    => 1650,
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
                'pending_points'       => 120,
                'lifetime_earned'      => 980,
                'lifetime_redeemed'    => 340,
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
                'pending_points'       => 0,
                'lifetime_earned'      => 6100,
                'lifetime_redeemed'    => 1280,
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
                'pending_points'       => 250,
                'lifetime_earned'      => 24900,
                'lifetime_redeemed'    => 6500,
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
                'pending_points'       => 0,
                'lifetime_earned'      => 15300,
                'lifetime_redeemed'    => 3250,
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
