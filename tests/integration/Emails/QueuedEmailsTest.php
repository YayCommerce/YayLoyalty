<?php
namespace YayLoyalty\Tests\Integration\Emails;

use YayLoyalty\Engine\Emails\EmailThrottle;
use YayLoyalty\Engine\Emails\PointsExpiredEmail;
use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;
use YayLoyalty\Engine\Emails\QueuedEmails;
use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/** The expiry emails' paced queue: 15 s slots, retry after a transport failure, and no retry for a skip. */
final class QueuedEmailsTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    /** @var array<int, array<string, mixed>> wp_mail() arguments, in send order. */
    private $sent = [];

    /** @var bool What the stand-in transport reports for each send. */
    private $mail_result = true;

    /** @var int|null The pinned `emails.throttle_per_week`. */
    private $throttle = null;

    /** @var array<string, \WC_Email> Emails this test added to the mailer, if the mailer lacked them. */
    private $added_emails = [];

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();

        // The fixture switches every WooCommerce email off and fails every send; this test sends the two expiry emails.
        foreach ( [ PointsExpiringSoonEmail::ID, PointsExpiredEmail::ID ] as $email_id ) {
            remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_false' );
            add_filter( 'woocommerce_email_enabled_' . $email_id, '__return_true' );
        }

        remove_filter( 'pre_wp_mail', '__return_false' );
        add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );
        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

        // The mailer is built once per process, so it lacks these emails when it was built while the site had expiry off.
        $mailer = WC()->mailer();

        foreach ( [ new PointsExpiringSoonEmail(), new PointsExpiredEmail() ] as $email ) {
            if ( ! isset( $mailer->emails[ $email->id ] ) ) {
                $this->added_emails[ $email->id ] = $email;
                $mailer->emails[ $email->id ]     = $email;
            }
        }
    }

    protected function tearDown(): void {
        remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );

        foreach ( [ PointsExpiringSoonEmail::ID, PointsExpiredEmail::ID ] as $email_id ) {
            remove_filter( 'woocommerce_email_enabled_' . $email_id, '__return_true' );
        }

        foreach ( array_keys( $this->added_emails ) as $email_id ) {
            unset( WC()->mailer()->emails[ $email_id ] );
        }

        $this->added_emails = [];
        as_unschedule_all_actions( QueuedEmails::HOOK );
        delete_option( QueuedEmails::OPTION_NEXT_SLOT );
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

        return $this->mail_result;
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [
            'schema_version'        => SettingsHelper::SCHEMA_VERSION,
            'points_label_singular' => 'Point',
            'points_label_plural'   => 'Points',
            'emails'                => [ 'throttle_per_week' => $this->throttle ],
            'expiry'                => [
                'enabled'         => true,
                'mode'            => ExpiryMode::INACTIVITY,
                'inactivity_days' => 365,
                'batch_months'    => 12,
                'reminder_days'   => 30,
            ],
        ];
    }

    public function test_queued_emails_take_slots_15_seconds_apart(): void {
        $before = time();

        foreach ( [ 101, 102, 103 ] as $entry_id ) {
            QueuedEmails::enqueue( PointsExpiredEmail::ID, [ $entry_id ] );
        }

        $slots = array_map(
            static function ( int $entry_id ): int {
                return (int) as_next_scheduled_action(
                    QueuedEmails::HOOK,
                    [
                        'email'   => PointsExpiredEmail::ID,
                        'args'    => [ $entry_id ],
                        'attempt' => 1,
                    ],
                    ExpiryRunner::GROUP
                );
            },
            [ 101, 102, 103 ]
        );

        $this->assertGreaterThanOrEqual( $before, $slots[0] );
        $this->assertSame( 15, $slots[1] - $slots[0] );
        $this->assertSame( 15, $slots[2] - $slots[1] );
        $this->assertSame( $slots[2] + 15, (int) get_option( QueuedEmails::OPTION_NEXT_SLOT ) );
    }

    public function test_a_failed_send_is_retried_after_the_backoff(): void {
        $user_id           = $this->customer_with_email();
        $args              = [ $user_id, 120, Dates::offset( 10 * DAY_IN_SECONDS ), true ];
        $this->mail_result = false;
        $before            = time();

        ( new QueuedEmails() )->handle( PointsExpiringSoonEmail::ID, $args, 1 );

        $retry = as_next_scheduled_action(
            QueuedEmails::HOOK,
            [
                'email'   => PointsExpiringSoonEmail::ID,
                'args'    => $args,
                'attempt' => 2,
            ],
            ExpiryRunner::GROUP
        );
        $this->assertCount( 1, $this->sent );
        $this->assertGreaterThanOrEqual( $before + 300, (int) $retry );

        as_unschedule_all_actions( QueuedEmails::HOOK );
        ( new QueuedEmails() )->handle( PointsExpiringSoonEmail::ID, $args, 4 );

        $this->assertFalse( as_has_scheduled_action( QueuedEmails::HOOK ), 'Attempt 4 is the last.' );
    }

    public function test_a_cap_skipped_send_is_not_retried(): void {
        $this->throttle = 1;
        $user_id        = $this->customer_with_email();
        $this->ledger_service()->earn( $this->earn_draft( $user_id, 1, 300 ) );
        update_option( ExpiryRunner::OPTION_APPLIED, ExpiryPolicy::current()->signature(), false );
        ( new CustomerRepository() )->set_expires_at( $user_id, Dates::offset( -DAY_IN_SECONDS ) );
        $entry = $this->ledger_service()->expire( $user_id, Dates::now() )->get_entry();
        update_user_meta( $user_id, EmailThrottle::META_SENDS, [ time() ] );

        ( new QueuedEmails() )->handle( PointsExpiredEmail::ID, [ (int) $entry->get_id() ], 1 );

        $this->assertCount( 0, $this->sent );
        $this->assertFalse( as_has_scheduled_action( QueuedEmails::HOOK ) );
    }
}
