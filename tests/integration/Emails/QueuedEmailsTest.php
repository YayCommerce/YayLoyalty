<?php
namespace YayLoyalty\Tests\Integration\Emails;

use YayLoyalty\Engine\Emails\EmailThrottle;
use YayLoyalty\Engine\Emails\PointsExpiredEmail;
use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;
use YayLoyalty\Engine\Emails\QueuedEmails;
use YayLoyalty\Engine\Expiry\ExpiryPolicy;
use YayLoyalty\Engine\Expiry\ExpiryRunner;
use YayLoyalty\Enums\ExpiryMode;
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

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->send_emails_for_real( [ $this, 'capture_mail' ], PointsExpiringSoonEmail::class, PointsExpiredEmail::class );
        $this->pin_expiry_settings( null );
    }

    protected function tearDown(): void {
        as_unschedule_all_actions( QueuedEmails::HOOK );
        delete_option( QueuedEmails::OPTION_NEXT_SLOT );
        delete_option( ExpiryRunner::OPTION_APPLIED );
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

    /** Pins expiry on in inactivity mode, with $throttle as `emails.throttle_per_week`. cleanup_orders() unpins it. */
    private function pin_expiry_settings( ?int $throttle ): void {
        $this->pin_settings(
            [
                'points_label_singular' => 'Point',
                'points_label_plural'   => 'Points',
                'emails'                => [ 'throttle_per_week' => $throttle ],
                'expiry'                => [
                    'enabled'         => true,
                    'mode'            => ExpiryMode::INACTIVITY,
                    'inactivity_days' => 365,
                    'batch_months'    => 12,
                    'reminder_days'   => 30,
                ],
            ]
        );
    }

    /** When the queue next sends attempt $attempt of $email_id with $args; 0 when it is not scheduled. */
    private static function scheduled_at( string $email_id, array $args, int $attempt ): int {
        return (int) as_next_scheduled_action(
            QueuedEmails::HOOK,
            [
                'email'   => $email_id,
                'args'    => $args,
                'attempt' => $attempt,
            ],
            ExpiryRunner::GROUP
        );
    }

    public function test_queued_emails_take_slots_15_seconds_apart(): void {
        $before = time();

        foreach ( [ 101, 102, 103 ] as $entry_id ) {
            QueuedEmails::enqueue( PointsExpiredEmail::ID, [ $entry_id ] );
        }

        $slots = array_map(
            static function ( int $entry_id ): int {
                return self::scheduled_at( PointsExpiredEmail::ID, [ $entry_id ], 1 );
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

        $this->assertCount( 1, $this->sent );
        $this->assertGreaterThanOrEqual( $before + 300, self::scheduled_at( PointsExpiringSoonEmail::ID, $args, 2 ) );

        as_unschedule_all_actions( QueuedEmails::HOOK );
        ( new QueuedEmails() )->handle( PointsExpiringSoonEmail::ID, $args, 4 );

        $this->assertFalse( as_has_scheduled_action( QueuedEmails::HOOK ), 'Attempt 4 is the last.' );
    }

    public function test_a_cap_skipped_send_is_not_retried(): void {
        $this->pin_expiry_settings( 1 );
        $user_id = $this->customer_with_email();
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
