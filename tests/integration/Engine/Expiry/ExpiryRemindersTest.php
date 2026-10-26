<?php
namespace YayLoyalty\Tests\Integration\Engine\Expiry;

use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;
use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Engine\Expiry\ExpiryReminders;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Table;

/** The Points expiring soon email goes out exactly once per expiry date, through the real expiry job and WooCommerce's mailer. */
final class ExpiryRemindersTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    /** @var array<int, array<string, mixed>> wp_mail() arguments, in send order. */
    private $sent = [];

    /** @var string The pinned `expiry.mode`. */
    private $mode = ExpiryMode::INACTIVITY;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();

        // The fixture switches every WooCommerce email off and fails every send; this test sends this one email,
        // which is off by default.
        remove_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
        add_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_true' );
        remove_filter( 'pre_wp_mail', '__return_false' );
        add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        remove_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
        remove_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_true' );
        remove_filter( 'yayloyalty_expiry_modes', [ $this, 'add_batch_mode' ] );
        delete_option( ExpiryRunner::OPTION_APPLIED );

        // cleanup_orders() removes the fixture's short-circuit, which setUp() already took off.
        add_filter( 'pre_wp_mail', '__return_false' );
        $this->cleanup_orders();

        parent::tearDown();
    }

    /**
     * Records the mail in place of the transport.
     *
     * @param mixed                $result
     * @param array<string, mixed> $atts
     */
    public function capture_mail( $result, array $atts ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter's signature.
        $this->sent[] = $atts;

        return true;
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [
            'schema_version'        => SettingsHelper::SCHEMA_VERSION,
            'points_label_singular' => 'Point',
            'points_label_plural'   => 'Points',
            'emails'                => [ 'throttle_per_week' => null ],
            'expiry'                => [
                'enabled'         => true,
                'mode'            => $this->mode,
                'inactivity_days' => 365,
                'batch_months'    => 12,
                'reminder_days'   => 30,
            ],
        ];
    }

    public function test_inactivity_reminds_once_inside_the_window(): void {
        $user_id = $this->customer_due_in( 10 );

        ( new ExpiryRunner() )->run();
        ( new ExpiryRunner() )->run();

        $row = $this->customer_row( $user_id );

        $this->assertCount( 1, $this->sent );
        $this->assertStringContainsString( '300 Points', (string) $this->sent[0]['message'] );
        $this->assertNotNull( $row['reminder_sent_at'] );
        $this->assertSame( $row['expires_at'], $row['reminder_for'], 'The claim records which date it announced.' );
    }

    public function test_a_date_outside_the_window_is_not_reminded(): void {
        $this->customer_due_in( 31 );

        ( new ExpiryRunner() )->run();

        $this->assertCount( 0, $this->sent );
    }

    public function test_a_reminder_for_an_earlier_date_allows_one_more(): void {
        $user_id = $this->customer_due_in( 10 );
        // The last reminder announced a date that activity has since moved.
        $this->set_reminder_for( $user_id, Dates::offset( -355 * DAY_IN_SECONDS ) );

        ( new ExpiryRunner() )->run();

        $this->assertCount( 1, $this->sent );
    }

    public function test_a_date_already_announced_is_not_announced_again(): void {
        $user_id = $this->customer_due_in( 10 );
        // Announced already, however far ahead: lowering reminder_days must not re-announce it.
        $this->set_reminder_for( $user_id, (string) $this->customer_row( $user_id )['expires_at'] );

        ( new ExpiryRunner() )->run();

        $this->assertCount( 0, $this->sent );
    }

    public function test_nothing_is_claimed_while_the_email_is_off_or_dates_unapplied(): void {
        $user_id = $this->customer_due_in( 10 );

        add_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
        ( new ExpiryRunner() )->run();
        remove_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
        $this->assertNull( $this->customer_row( $user_id )['reminder_sent_at'] );

        update_option( ExpiryRunner::OPTION_APPLIED, 'stale' );
        ( new ExpiryReminders( new CustomerRepository(), new LotRepository() ) )->run( ExpiryPolicy::current(), Dates::now() );
        $this->assertNull( $this->customer_row( $user_id )['reminder_sent_at'] );
        $this->assertCount( 0, $this->sent );
    }

    /**
     * Lets this build run batch expiry, as Pro does.
     *
     * @param string[] $modes
     * @return string[]
     */
    public function add_batch_mode( array $modes ): array {
        return array_merge( $modes, [ ExpiryMode::BATCH ] );
    }

    public function test_batch_reminds_due_lots_once_with_their_sum_and_first_date(): void {
        $lots = $this->customer_with_lots( [ 5, 20, 200 ] );

        ( new ExpiryRunner() )->run();
        ( new ExpiryRunner() )->run();

        $this->assertCount( 1, $this->sent );
        $this->assertStringContainsString( '200 Points', (string) $this->sent[0]['message'] );
        $this->assertStringContainsString( 'will expire, starting', (string) $this->sent[0]['message'] );
        $this->assertNotNull( $this->lot_row( $lots[0] )['reminded_at'] );
        $this->assertNotNull( $this->lot_row( $lots[1] )['reminded_at'] );
        $this->assertNull( $this->lot_row( $lots[2] )['reminded_at'] );
    }

    public function test_a_lot_entering_the_window_later_gets_its_own_reminder(): void {
        $lots = $this->customer_with_lots( [ 5, 200 ] );
        ( new ExpiryRunner() )->run();

        $this->set_lot_expires_at( $lots[1], Dates::offset( 25 * DAY_IN_SECONDS ) );
        ( new ExpiryRunner() )->run();

        $this->assertCount( 2, $this->sent );
        $this->assertStringContainsString( '100 Points', (string) $this->sent[1]['message'] );
        $this->assertStringContainsString( 'will expire on', (string) $this->sent[1]['message'] );
    }

    public function test_a_restamp_that_moves_a_lot_clears_its_reminder(): void {
        $lots = $this->customer_with_lots( [ 5 ] );
        ( new ExpiryRunner() )->run();
        $this->assertNotNull( $this->lot_row( $lots[0] )['reminded_at'] );

        ( new LotRepository() )->restamp_open_expiry( 12, ExpiryPolicy::grace_floor( Dates::now() ), false );

        $this->assertNull( $this->lot_row( $lots[0] )['reminded_at'] );
    }

    /**
     * A batch-mode customer with one 100-point lot per entry, each lot expiring that many days from now.
     *
     * @param int[] $days_ahead
     * @return int[] The lot ids, in the same order.
     */
    private function customer_with_lots( array $days_ahead ): array {
        global $wpdb;

        $this->mode = ExpiryMode::BATCH;
        add_filter( 'yayloyalty_expiry_modes', [ $this, 'add_batch_mode' ] );

        $user_id = $this->create_customer( 'yl_it_' . wp_generate_password( 8, false ) . '@example.com' );
        $lot_ids = [];

        foreach ( array_values( $days_ahead ) as $index => $days ) {
            $this->ledger_service()->earn( $this->earn_draft( $user_id, $index + 1, 100 ) );

            $lot_ids[] = (int) $wpdb->get_var( 'SELECT MAX(id) FROM `' . esc_sql( Table::name( 'lots' ) ) . '`' ); // phpcs:ignore WordPress.DB -- the test reads its own scratch table.
            $this->set_lot_expires_at( end( $lot_ids ), Dates::offset( $days * DAY_IN_SECONDS ) );
        }

        update_option( ExpiryRunner::OPTION_APPLIED, ExpiryPolicy::current()->signature(), false );

        return $lot_ids;
    }

    private function set_lot_expires_at( int $lot_id, string $expires_at ): void {
        global $wpdb;

        $wpdb->update( Table::name( 'lots' ), [ 'expires_at' => $expires_at ], [ 'id' => $lot_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test sets the column directly.
    }

    /** @return array<string, mixed> */
    private function lot_row( int $lot_id ): array {
        global $wpdb;

        return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . esc_sql( Table::name( 'lots' ) ) . '` WHERE id = %d', $lot_id ), ARRAY_A ); // phpcs:ignore WordPress.DB -- the test reads its own scratch table.
    }

    /** A customer with 300 points whose stamped expiry is $days from now, under the pinned settings. */
    private function customer_due_in( int $days ): int {
        $user_id = $this->create_customer( 'yl_it_' . wp_generate_password( 8, false ) . '@example.com' );

        $this->ledger_service()->earn( $this->earn_draft( $user_id, 1, 300 ) );
        update_option( ExpiryRunner::OPTION_APPLIED, ExpiryPolicy::current()->signature(), false );
        ( new CustomerRepository() )->set_expires_at( $user_id, Dates::offset( $days * DAY_IN_SECONDS ) );

        return $user_id;
    }

    private function set_reminder_for( int $user_id, string $expires_at ): void {
        global $wpdb;

        $wpdb->update( Table::name( 'customers' ), [ 'reminder_for' => $expires_at ], [ 'user_id' => $user_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test sets the column directly.
    }
}
