<?php
namespace YayLoyalty\Tests\Integration\Emails;

use YayLoyalty\Database\Installer;
use YayLoyalty\Engine\Emails\EmailThrottle;
use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** The Points earned email's send path, through the real earning hooks and WooCommerce's mailer, and what it renders. */
final class PointsEarnedEmailTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    /** @var array<int, array<string, mixed>> wp_mail() arguments, in send order. */
    private $sent = [];

    /** @var bool What the stand-in transport reports for each send. */
    private $mail_result = true;

    /** @var int Times throw_mail() ran. */
    private $throws = 0;

    /** @var int|null The pinned `emails.throttle_per_week`. */
    private $throttle = null;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();

        // The fixture switches every WooCommerce email off and fails every send; this test sends this one email.
        remove_filter( 'woocommerce_email_enabled_' . PointsEarnedEmail::ID, '__return_false' );
        remove_filter( 'pre_wp_mail', '__return_false' );
        add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10, 2 );

        // Settings are the site's own option; pin what the assertions depend on.
        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 10 );
        remove_filter( 'pre_wp_mail', [ $this, 'throw_mail' ], 1 );
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        remove_filter( 'woocommerce_email_enabled_' . PointsEarnedEmail::ID, '__return_false' );

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

    /** Stands in for an SMTP plugin that throws on a dead server. */
    public function throw_mail(): void {
        ++$this->throws;

        throw new \RuntimeException( 'SMTP down' );
    }

    /** @return array<string, mixed> */
    public function pinned_settings(): array {
        return [
            'schema_version'        => SettingsHelper::SCHEMA_VERSION,
            'points_label_singular' => 'Point',
            'points_label_plural'   => 'Points',
            'emails'                => [ 'throttle_per_week' => $this->throttle ],
        ];
    }

    /** Transaction::after_commit() catches what the email throws, so a broken mailer cannot undo the award. */
    public function test_a_throwing_mailer_leaves_the_award_intact(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();

        add_filter( 'pre_wp_mail', [ $this, 'throw_mail' ], 1 );

        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $this->assertSame( 1, $this->throws );
        $this->assertSame( [ 100 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 100, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_a_refired_completion_sends_no_second_email(): void {
        $this->create_campaign();
        $order = $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

        do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'completed', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WooCommerce's own hook.

        $this->assertCount( 1, $this->sent );
    }

    /** With deferred emails, the send runs later from Action Scheduler, after a refund may have taken the points back. */
    public function test_a_deferred_send_skips_an_award_refunded_first(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();

        $relayed = [];
        $relay   = static function ( ...$args ) use ( &$relayed ): void {
            $relayed[] = $args;
        };
        add_action( Emails::POINTS_EARNED_ACTION, $relay, 1, 5 );

        // Holds the send WooCommerce would otherwise make at once, as deferral does.
        add_filter( 'woocommerce_email_enabled_' . PointsEarnedEmail::ID, '__return_false' );
        $order = $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );
        remove_filter( 'woocommerce_email_enabled_' . PointsEarnedEmail::ID, '__return_false' );
        remove_action( Emails::POINTS_EARNED_ACTION, $relay, 1 );

        $entry_id = (int) $this->ledger_rows( $user_id )[0]['id'];
        $this->assertSame( [ [ $entry_id ] ], $relayed, 'The relay passes the ledger row id and nothing else.' );

        $this->refund_lines( $order, [ 0 ] );
        $this->assertSame( [ 100, -100 ], $this->ledger_points( $user_id ) );

        // What Action Scheduler runs for the queued email.
        do_action( Emails::POINTS_EARNED_ACTION . '_notification', $entry_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name.

        $this->assertCount( 0, $this->sent );
    }

    public function test_the_weekly_cap_skips_the_send_at_the_cap(): void {
        $this->throttle = 1;
        $this->create_campaign();
        $user_id = $this->customer_with_email();

        // A send the transport refuses doesn't count against the week.
        $this->mail_result = false;
        $this->transition( $this->create_order( $user_id, [ 10 ] ), 'completed' );
        $this->assertCount( 1, $this->sent );
        $this->assertSame( '', get_user_meta( $user_id, EmailThrottle::META_SENDS, true ) );

        // The first accepted send reaches the cap, so the next award sends nothing.
        $this->mail_result = true;
        $this->transition( $this->create_order( $user_id, [ 10 ] ), 'completed' );
        $this->transition( $this->create_order( $user_id, [ 10 ] ), 'completed' );
        $this->assertCount( 2, $this->sent );

        // A send older than 7 days no longer counts.
        update_user_meta( $user_id, EmailThrottle::META_SENDS, [ time() - WEEK_IN_SECONDS - 60 ] );
        $this->transition( $this->create_order( $user_id, [ 10 ] ), 'completed' );
        $this->assertCount( 3, $this->sent );

        $this->assertSame( [ 10, 10, 10, 10 ], $this->ledger_points( $user_id ), 'A skipped send never costs the award.' );
    }

    /** Guards LoyaltyEmail::get_content_plain()'s entity decoding. */
    public function test_plain_text_decodes_entities(): void {
        $user_id = $this->customer_with_email();
        update_user_meta( $user_id, 'first_name', "O'Brien" );

        $plain = $this->preview_as( $user_id )->get_content_plain();

        $this->assertStringContainsString( "Hi O'Brien,", $plain );
        $this->assertStringNotContainsString( '&#039;', $plain );
        $this->assertStringNotContainsString( '<strong>', $plain );
    }

    /** The value line stays off until redemption ships, even with a live discount reward. */
    public function test_the_value_line_is_hidden_by_default(): void {
        $this->seed_discount_reward( Status::ACTIVE );
        $email = $this->preview_as( $this->customer_with_email() );

        $this->assertStringNotContainsString( 'worth', $email->get_content_html() );
        $this->assertStringNotContainsString( 'worth', $email->get_content_plain() );
        $this->assertSame( '', $email->get_template_values()['balance_value'] );
    }

    /** WooCommerce's settings preview hands the email a dummy order; the preview hook swaps in a sample award. */
    public function test_the_preview_shows_a_sample_award(): void {
        $user_id = $this->customer_with_email();
        $email   = new PointsEarnedEmail();
        $email->set_object( new \WC_Order() );
        wp_set_current_user( $user_id );

        try {
            $email = apply_filters( 'woocommerce_prepare_email_for_preview', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WooCommerce's own hook as its preview does.
        } finally {
            wp_set_current_user( 0 );
        }

        $this->assertStringContainsString( 'You earned 50 Points', $email->get_subject() );
        $this->assertStringContainsString( 'You earned <strong>50 Points</strong>.', $email->get_content_html() );
        $this->assertStringContainsString( 'Your balance is now <strong>150 Points</strong>.', $email->get_content_html() );
        $this->assertSame( get_userdata( $user_id )->display_name, $email->get_template_values()['greeting_name'] );
    }

    /** The sample award, greeting $user_id. */
    private function preview_as( int $user_id ): PointsEarnedEmail {
        $email = new PointsEarnedEmail();
        wp_set_current_user( $user_id );

        try {
            $email->prepare_preview();
        } finally {
            wp_set_current_user( 0 );
        }

        return $email;
    }

    /** The built-in reward exactly as an install seeds it, then set to $status; the scratch tables start empty. */
    private function seed_discount_reward( string $status ): void {
        $seed = new \ReflectionMethod( Installer::class, 'seed_builtin_reward' );
        $seed->setAccessible( true );
        $seed->invoke( null );

        $rewards = new RewardRepository();
        $rewards->update( $rewards->find_by_slug( RewardRepository::CART_DISCOUNT_SLUG )->get_id(), [ 'status' => $status ] );
    }
}
