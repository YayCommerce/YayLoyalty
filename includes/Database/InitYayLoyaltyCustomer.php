<?php
namespace YayLoyalty\Database;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * One-time starter data: creates 5 real WooCommerce customers, gives each a
 * real ledger history via `LedgerService::adjust()`, then fills in the
 * columns the ledger doesn't touch — so a fresh install's admin Customers
 * list AND its ledger history are never empty. See
 * docs/codebase-summary.md.
 *
 * Split out of Installer.php to keep that file from growing further (already
 * past the project's 200-line guideline — see docs/codebase-summary.md) —
 * this is also a distinct concern: "create starter data" vs.
 * "create/verify tables".
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
 * BALANCE / LIFETIME_EARNED COME FROM REAL LEDGER ROWS, NOT A DIRECT WRITE.
 * Each customer gets two `LedgerService::adjust()` calls — a positive
 * "migrated balance" entry and a smaller negative "correction" entry — so
 * `customers.balance = SUM(ledger.points)` holds because there are real
 * ledger rows behind it, the same invariant every other write path keeps.
 * `lifetime_redeemed` is left at 0: `ADJUST` rows never count toward it (only
 * `REDEEM`/`REFUND_REDEEM` do), and there is no real redemption feature yet
 * to produce one honestly — see docs/project-roadmap.md.
 *
 * SECOND AUTHORISED CONSTRUCTION SITE FOR LedgerService. Its own docblock
 * names exactly two construction sites — `RestAPI::controllers()` and this
 * one — so that "only LedgerService writes the ledger" stays a reviewable
 * convention, not a promise anything can quietly break. This install-time
 * bootstrap step runs once, outside any request, and reuses the *service*
 * (so aggregate math stays centralised) rather than writing
 * `LedgerRepository`/`CustomerRepository` directly, which would have
 * open-coded `LedgerService::apply_aggregates()`'s rules a second time.
 */
final class InitYayLoyaltyCustomer {

    public const OPTION_INITIALIZED = 'yayloyalty_demo_customers_initialized';

    /** Column order both build_row() and $format below must agree on. */
    private const ROW_FORMAT = [
        'pending_points'   => '%d',
        // Money: bound as %s, never %f — see code-standards.md section 5.
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

        $customers      = new CustomerRepository();
        $ledger_service = new LedgerService( new LedgerRepository(), $customers );

        // Starter accounts are throwaway data; a real "new account" email from
        // a plugin install would be surprising.
        add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );

        foreach ( self::customer_data() as $data ) {
            self::create_one( $customers, $ledger_service, $data );
        }

        remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false' );
    }

    private static function create_one( CustomerRepository $customers, LedgerService $ledger_service, array $data ): void {
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

        // Caught, not left to escape: each write below is its own statement/
        // transaction (no shared wrapper across customers), and this runs
        // inside Installer::install(), which plugins_loaded calls on every
        // request until it completes. An uncaught exception here would fatal
        // whichever page load hits it, and — because OPTION_INITIALIZED is
        // already claimed — silently skip every customer after the failed
        // one on every later request too. One failed row logs and the loop
        // moves on; whatever this customer already wrote (WP user, or some
        // of its two ledger entries) is left as-is rather than rolled back.
        try {
            // actor_id 0 → NULL: this adjustment isn't attributed to any real
            // admin — see LedgerService::adjust()'s own actor_id handling.
            foreach ( $data['ledger_entries'] as [ $points, $note ] ) {
                $ledger_service->adjust( $user_id, $points, $note, 0 );
            }

            $customers->update( $user_id, self::build_row( $user_id, $data ), array_values( self::ROW_FORMAT ) );
        } catch ( \Throwable $exception ) {
            Log::error( 'InitYayLoyaltyCustomer', 'failed to write customer data for user ' . $user_id . ': ' . $exception->getMessage() );
        }
    }

    /**
     * Columns `LedgerService::adjust()` never touches: it sets balance and
     * (for a positive entry) lifetime_earned, nothing else — no
     * pending_points, no expiry/reminder/birthday/referral fields, and no
     * last_activity_at either (a manual ADJUST isn't "customer activity" —
     * see `LedgerType::is_activity()` — so that column stays NULL unless set
     * here, same as a real admin adjustment would leave it).
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
            // Structurally unique against the UNIQUE KEY — no collision-retry needed.
            'referral_code'    => 'YAY' . $user_id,
            'birthday_month'   => $data['birthday'][0] ?? null,
            'birthday_day'     => $data['birthday'][1] ?? null,
            'joined_at'        => Dates::offset( $data['joined_offset'] ),
        ];
    }

    /**
     * Pure data: 5 people, one per balance/expiry combination the admin list's
     * filters offer. Offsets are relative (Dates::offset()) so "within 30
     * days" is still within 30 days a month after install — never hardcoded.
     *
     * `ledger_entries` is `[points, note]` pairs applied in order via
     * `LedgerService::adjust()`. Only the first (positive) entry counts
     * toward `lifetime_earned`; both together net out to `balance`. E.g.
     * Marta's `+1400` then `-1650` nets to `balance = -250`,
     * `lifetime_earned = 1400` — the "negative" bucket with a real earn
     * behind it, not a fabricated negative number.
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
