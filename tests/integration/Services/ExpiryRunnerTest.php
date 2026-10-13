<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Pro\YayLoyaltyPro;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/** Expiry through LedgerService::expire(): one EXPIRE row per due customer, re-checked under the lock, in both modes. */
final class ExpiryRunnerTest extends IntegrationTestCase {

    private const USER_ID = 717171;

    /** @var array<string, mixed> The `expiry` group the site option reads as. */
    private $expiry = [
        'enabled'         => true,
        'mode'            => 'inactivity',
        'inactivity_days' => 365,
    ];

    protected function setUp(): void {
        parent::setUp();
        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        add_filter( 'pre_option_' . ExpiryRunner::OPTION_APPLIED, [ $this, 'applied_signature' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        remove_filter( 'pre_option_' . ExpiryRunner::OPTION_APPLIED, [ $this, 'applied_signature' ] );
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [ 'expiry' => $this->expiry ];
    }

    /** The stored dates count as computed from the pinned settings. */
    public function applied_signature(): string {
        return ExpiryPolicy::current()->signature();
    }

    public function test_inactivity_expires_the_whole_balance_and_every_lot(): void {
        $this->earn_then_back_date( 400, 300 );

        $outcome = $this->ledger_service()->expire( self::USER_ID, Dates::now() );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $row = $this->ledger_rows( self::USER_ID )[2];
        $this->assertSame( [ 'expire', -700, 'expiry' ], [ $row['type'], (int) $row['points'], $row['source'] ] );

        $customer = $this->customer_row( self::USER_ID );
        $this->assertSame( 0, (int) $customer['balance'] );
        $this->assertNull( $customer['expires_at'] );
        $this->assertSame( [ 'expired', 'expired' ], $this->lot_statuses() );
    }

    public function test_activity_after_queueing_skips_the_customer(): void {
        $this->earn_then_back_date( 700 );
        $this->assertSame( [ self::USER_ID ], ( new CustomerRepository() )->due_user_ids( Dates::now(), 100 ) );

        $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9100, 50 ) );
        $outcome = $this->ledger_service()->expire( self::USER_ID, Dates::now() );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( LedgerService::REASON_NOT_DUE, $outcome->get_reason() );
        $this->assertSame( [ 700, 50 ], $this->ledger_points( self::USER_ID ) );
    }

    public function test_a_second_run_the_same_day_is_a_duplicate(): void {
        $this->earn_then_back_date( 700 );
        $now = Dates::now();

        $this->ledger_service()->expire( self::USER_ID, $now );
        $second = $this->ledger_service()->expire( self::USER_ID, $now );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( [ 700, -700 ], $this->ledger_points( self::USER_ID ) );
    }

    public function test_an_earn_sets_expiry_to_its_date_plus_the_window(): void {
        $earn = $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9001, 100, '2026-09-01 10:00:00' ) )->get_entry();

        $this->assertSame( '2026-09-01 10:00:00', $earn->get_created_at() );
        $this->assertSame( '2027-09-01 10:00:00', $this->customer_row( self::USER_ID )['expires_at'] );
    }

    public function test_batch_expires_only_due_lots_and_moves_expiry_to_the_next_lot(): void {
        global $wpdb;

        $this->use_batch();
        $this->earn_dated(
            [
                400 => '2026-01-01 00:00:00',
                300 => '2026-02-01 00:00:00',
                200 => '2026-03-01 00:00:00',
            ]
        );
        $dates = [ Dates::offset( -DAY_IN_SECONDS ), Dates::offset( 30 * DAY_IN_SECONDS ), Dates::offset( 60 * DAY_IN_SECONDS ) ];

        foreach ( $this->lot_ids() as $i => $lot_id ) {
            $wpdb->update( Table::name( 'lots' ), [ 'expires_at' => $dates[ $i ] ], [ 'id' => $lot_id ] ); // phpcs:ignore WordPress.DB -- a test back-dates the rows directly.
        }

        $outcome = $this->ledger_service()->expire( self::USER_ID, Dates::now() );

        $this->assertSame( Outcome::AWARDED, $outcome->get_status() );
        $this->assertSame( -400, $outcome->get_entry()->get_points() );
        $this->assertSame( [ 'expired', 'open', 'open' ], $this->lot_statuses() );
        $this->assertSame( $dates[1], $this->customer_row( self::USER_ID )['expires_at'] );
    }

    public function test_batch_newest_spend_order_takes_the_newest_lot_first(): void {
        $this->use_batch( 'newest' );
        $this->earn_dated(
            [
                400 => '2026-01-01 00:00:00',
                300 => '2026-02-01 00:00:00',
                200 => '2026-03-01 00:00:00',
            ]
        );

        $deduct = $this->ledger_service()->adjust( self::USER_ID, -250, 'test', 1 );

        global $wpdb;
        $allocated = $wpdb->get_col( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
            $wpdb->prepare( 'SELECT points FROM `' . esc_sql( Table::name( 'lot_allocations' ) ) . '` WHERE ledger_id = %d ORDER BY id', $deduct->get_id() )
        );
        $this->assertSame( [ 200, 50 ], array_map( 'intval', $allocated ) );
    }

    public function test_without_pro_a_stored_batch_mode_runs_as_inactivity(): void {
        $this->use_batch();
        remove_filter( 'yayloyalty_expiry_modes', [ YayLoyaltyPro::class, 'add_expiry_modes' ] );

        try {
            $this->assertSame( ExpiryMode::INACTIVITY, ExpiryPolicy::current()->mode() );
        } finally {
            add_filter( 'yayloyalty_expiry_modes', [ YayLoyaltyPro::class, 'add_expiry_modes' ] );
        }
    }

    private function use_batch( string $spend_order = 'oldest' ): void {
        $this->expiry = [
            'enabled'      => true,
            'mode'         => 'batch',
            'batch_months' => 12,
            'spend_order'  => $spend_order,
        ];
    }

    /** @param array<int, string> $points_to_date Earns each amount at its date, oldest first. */
    private function earn_dated( array $points_to_date ): void {
        $order = 9001;

        foreach ( $points_to_date as $points => $created_at ) {
            $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, $order++, $points, $created_at ) );
        }
    }

    /** @return int[] */
    private function lot_ids(): array {
        global $wpdb;

        return array_map(
            'intval',
            (array) $wpdb->get_col( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
                $wpdb->prepare( 'SELECT id FROM `' . esc_sql( Table::name( 'lots' ) ) . '` WHERE user_id = %d ORDER BY id', self::USER_ID )
            )
        );
    }

    /** Earns each amount as its own order, then moves the customer's expiry to yesterday. */
    private function earn_then_back_date( int ...$amounts ): void {
        global $wpdb;

        foreach ( $amounts as $i => $points ) {
            $this->ledger_service()->earn( $this->earn_draft( self::USER_ID, 9001 + $i, $points ) );
        }

        $wpdb->update( Table::name( 'customers' ), [ 'expires_at' => Dates::offset( -DAY_IN_SECONDS ) ], [ 'user_id' => self::USER_ID ] ); // phpcs:ignore WordPress.DB -- a test back-dates the row directly.
    }

    /** @return string[] */
    private function lot_statuses(): array {
        global $wpdb;

        return (array) $wpdb->get_col( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
            $wpdb->prepare( 'SELECT status FROM `' . esc_sql( Table::name( 'lots' ) ) . '` WHERE user_id = %d ORDER BY id', self::USER_ID )
        );
    }
}
