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

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();

        // The fixture switches every WooCommerce email off and fails every send; this test sends this one email.
        remove_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
        remove_filter( 'pre_wp_mail', '__return_false' );
        add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        remove_filter( 'woocommerce_email_enabled_' . PointsExpiringSoonEmail::ID, '__return_false' );
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
                'mode'            => ExpiryMode::INACTIVITY,
                'inactivity_days' => 365,
                'reminder_days'   => 30,
            ],
        ];
    }

    public function test_inactivity_reminds_once_inside_the_window(): void {
        $user_id = $this->customer_due_in( 10 );

        ( new ExpiryRunner() )->run();
        ( new ExpiryRunner() )->run();

        $this->assertCount( 1, $this->sent );
        $this->assertStringContainsString( '300 Points', (string) $this->sent[0]['message'] );
        $this->assertNotNull( $this->customer_row( $user_id )['reminder_sent_at'] );
    }

    public function test_a_date_outside_the_window_is_not_reminded(): void {
        $this->customer_due_in( 31 );

        ( new ExpiryRunner() )->run();

        $this->assertCount( 0, $this->sent );
    }

    public function test_a_reminder_for_an_earlier_date_allows_one_more(): void {
        $user_id = $this->customer_due_in( 10 );
        // Sent 40 days ago: before this date's window opened (now + 10 − 30 = 20 days ago).
        $this->set_reminder_sent_at( $user_id, Dates::offset( -40 * DAY_IN_SECONDS ) );

        ( new ExpiryRunner() )->run();

        $this->assertCount( 1, $this->sent );
    }

    public function test_a_reminder_inside_this_window_blocks_another(): void {
        $user_id = $this->customer_due_in( 10 );
        // Sent 15 days ago: inside this date's window, which opened 20 days ago.
        $this->set_reminder_sent_at( $user_id, Dates::offset( -15 * DAY_IN_SECONDS ) );

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

    /** A customer with 300 points whose stamped expiry is $days from now, under the pinned settings. */
    private function customer_due_in( int $days ): int {
        $user_id = $this->create_customer( 'yl_it_' . wp_generate_password( 8, false ) . '@example.com' );

        $this->ledger_service()->earn( $this->earn_draft( $user_id, 1, 300 ) );
        update_option( ExpiryRunner::OPTION_APPLIED, ExpiryPolicy::current()->signature(), false );
        ( new CustomerRepository() )->set_expires_at( $user_id, Dates::offset( $days * DAY_IN_SECONDS ) );

        return $user_id;
    }

    private function set_reminder_sent_at( int $user_id, ?string $at ): void {
        global $wpdb;

        $wpdb->update( Table::name( 'customers' ), [ 'reminder_sent_at' => $at ], [ 'user_id' => $user_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the test sets the column directly.
    }
}
