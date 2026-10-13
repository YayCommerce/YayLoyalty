<?php
namespace YayLoyalty\Tests\Integration\Services;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Services\LedgerService;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/** Expiry through LedgerService::expire(): one EXPIRE row per due customer, re-checked under the lock. */
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
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [ 'expiry' => $this->expiry ];
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
