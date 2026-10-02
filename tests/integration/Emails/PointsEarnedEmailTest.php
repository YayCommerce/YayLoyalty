<?php
namespace YayLoyalty\Tests\Integration\Emails;

use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** Awards reach the customer as one Points earned email, through the real earning hooks and WooCommerce's mailer. */
final class PointsEarnedEmailTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    /** @var array<int, array<string, mixed>> wp_mail() arguments, in send order. */
    private $sent = [];

    /** @var int Times throw_mail() ran. */
    private $throws = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();

        // The fixture switches every WooCommerce email off, this one included.
        remove_filter( 'woocommerce_email_enabled_' . PointsEarnedEmail::ID, '__return_false' );

        $this->sent = [];
        add_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 5, 2 );

        // Settings are the site's own option; pin the labels the assertions spell out.
        add_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_wp_mail', [ $this, 'capture_mail' ], 5 );
        remove_filter( 'pre_wp_mail', [ $this, 'throw_mail' ], 1 );
        remove_filter( 'pre_option_' . SettingsHelper::OPTION_SETTINGS, [ $this, 'pinned_settings' ] );
        remove_all_filters( 'yayloyalty_points_earned_cta_url' );
        remove_all_filters( 'yayloyalty_points_earned_text' );
        $this->cleanup_orders();

        parent::tearDown();
    }

    /**
     * Records the mail and leaves $return alone, so the fixture's short-circuit still stops delivery.
     *
     * @param mixed                $result
     * @param array<string, mixed> $atts
     * @return mixed
     */
    public function capture_mail( $result, array $atts ) {
        $this->sent[] = $atts;

        return $result;
    }

    /** Stands in for an SMTP plugin that throws on a dead server. */
    public function throw_mail(): void {
        ++$this->throws;

        throw new \RuntimeException( 'SMTP down' );
    }

    public function pinned_settings(): array {
        return [
            'general' => [
                'points_label_singular' => 'Point',
                'points_label_plural'   => 'Points',
            ],
        ];
    }

    public function test_a_completed_order_mails_the_customer_once_with_points_order_and_balance(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();
        $order   = $this->transition( $this->create_order( $user_id, [ 60, 40 ] ), 'completed' );

        $this->assertCount( 1, $this->sent );
        $mail = $this->sent[0];

        $this->assertSame( get_userdata( $user_id )->user_email, $mail['to'] );
        $this->assertStringContainsString( 'You earned 100 Points', $mail['subject'] );
        $this->assertStringNotContainsString( '{points', $mail['subject'] );
        $this->assertStringContainsString( 'You earned <strong>100 Points</strong> for order #' . $order->get_order_number() . '.', $mail['message'] );
        $this->assertStringContainsString( 'Your balance is now <strong>100 Points</strong>.', $mail['message'] );
        $this->assertStringContainsString( esc_url( wc_get_page_permalink( 'myaccount' ) ), $mail['message'] );
    }

    public function test_a_refired_completion_is_a_duplicate_and_sends_nothing_more(): void {
        $this->create_campaign();
        $order = $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

        do_action( 'woocommerce_order_status_changed', $order->get_id(), 'processing', 'completed', $order ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WooCommerce's own hook.

        $this->assertCount( 1, $this->sent );
    }

    public function test_a_disabled_email_sends_nothing_and_the_award_stands(): void {
        add_filter( 'woocommerce_email_enabled_' . PointsEarnedEmail::ID, '__return_false' );
        $this->create_campaign();
        $user_id = $this->customer_with_email();

        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $this->assertCount( 0, $this->sent );
        $this->assertSame( [ 100 ], $this->ledger_points( $user_id ) );
    }

    public function test_a_customer_without_an_email_address_gets_nothing_and_keeps_the_award(): void {
        $this->create_campaign();
        $user_id = $this->create_customer();

        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $this->assertCount( 0, $this->sent );
        $this->assertSame( [ 100 ], $this->ledger_points( $user_id ) );
    }

    /** Transaction::after_commit() catches what the listener throws, so a broken mailer cannot undo the award. */
    public function test_a_throwing_mailer_leaves_the_award_intact(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();

        add_filter( 'pre_wp_mail', [ $this, 'throw_mail' ], 1 );

        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $this->assertSame( 1, $this->throws );
        $this->assertSame( [ 100 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 100, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_a_signup_award_says_it_was_for_creating_an_account(): void {
        $this->create_campaign( CampaignTrigger::SIGNUP );
        wp_set_current_user( 0 );

        $this->track_user(
            (int) wp_insert_user(
                [
                    'user_login' => 'yl_it_visitor_' . wp_generate_password( 8, false ),
                    'user_pass'  => wp_generate_password(),
                    'user_email' => 'yl_it_' . wp_generate_password( 8, false ) . '@example.com',
                    'role'       => 'customer',
                ]
            )
        );

        $this->assertCount( 1, $this->sent );
        $this->assertStringContainsString( 'You earned <strong>100 Points</strong> for creating an account.', $this->sent[0]['message'] );
    }

    public function test_a_review_award_says_it_was_for_reviewing_a_product(): void {
        $this->create_campaign( CampaignTrigger::REVIEW, [ 'require_verified' => false ] );

        $this->approve_new_review( $this->customer_with_email(), $this->create_product() );

        $this->assertCount( 1, $this->sent );
        $this->assertStringContainsString( 'You earned <strong>50 Points</strong> for reviewing a product.', $this->sent[0]['message'] );
    }

    public function test_filters_replace_the_sentence_and_the_link(): void {
        add_filter(
            'yayloyalty_points_earned_text',
            static function (): string {
                return 'Custom sentence.';
            }
        );
        add_filter(
            'yayloyalty_points_earned_cta_url',
            static function (): string {
                return 'https://example.com/rewards';
            }
        );
        $this->create_campaign();

        $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

        $this->assertCount( 1, $this->sent );
        $this->assertStringContainsString( 'Custom sentence.', $this->sent[0]['message'] );
        $this->assertStringContainsString( 'https://example.com/rewards', $this->sent[0]['message'] );
    }

    public function test_points_label_is_singular_only_at_one(): void {
        $this->assertSame( 'Point', SettingsHelper::points_label( 1 ) );
        $this->assertSame( 'Points', SettingsHelper::points_label( 0 ) );
        $this->assertSame( 'Points', SettingsHelper::points_label( 2 ) );
    }

    /** WooCommerce's settings preview hands the email a dummy order; the preview hook swaps in a sample award. */
    public function test_the_settings_preview_shows_a_sample_award(): void {
        $email = new PointsEarnedEmail();
        $email->set_object( new \WC_Order() );

        $email = apply_filters( 'woocommerce_prepare_email_for_preview', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WooCommerce's own hook as its preview does.

        $this->assertStringContainsString( 'You earned 50 Points', $email->get_subject() );
        $this->assertStringContainsString( 'You earned <strong>50 Points</strong>.', $email->get_content_html() );
        $this->assertStringContainsString( 'Your balance is now <strong>150 Points</strong>.', $email->get_content_html() );
    }

    public function test_the_plain_text_email_has_no_markup(): void {
        $email = apply_filters( 'woocommerce_prepare_email_for_preview', new PointsEarnedEmail() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- firing WooCommerce's own hook as its preview does.
        $plain = $email->get_content_plain();

        $this->assertStringContainsString( 'You earned 50 Points.', $plain );
        $this->assertStringContainsString( 'Your balance is now 150 Points.', $plain );
        $this->assertStringNotContainsString( '<strong>', $plain );
    }

    public function test_the_greeting_uses_the_first_name(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();
        update_user_meta( $user_id, 'first_name', 'Anna' );

        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $this->assertStringContainsString( 'Hi Anna,', $this->sent[0]['message'] );
    }

    public function test_the_greeting_falls_back_to_the_display_name(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();

        $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $this->assertStringContainsString( 'Hi ' . get_userdata( $user_id )->display_name . ',', $this->sent[0]['message'] );
    }

    /** WooCommerce's plain-text cleanup deletes entities it does not know, &#039; among them. */
    public function test_a_plain_text_send_keeps_apostrophes(): void {
        $email             = WC()->mailer()->get_emails()[ PointsEarnedEmail::ID ];
        $email->email_type = 'plain';
        $this->seed_discount_reward( Status::ACTIVE );
        $this->create_campaign();
        $user_id = $this->customer_with_email();
        update_user_meta( $user_id, 'first_name', "O'Brien" );

        try {
            $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );
        } finally {
            $email->email_type = $email->get_option( 'email_type' );
        }

        $this->assertStringContainsString( "Hi O'Brien,", $this->sent[0]['message'] );
        $this->assertStringContainsString( "That's worth", $this->sent[0]['message'] );
        $this->assertStringNotContainsString( '<strong>', $this->sent[0]['message'] );
    }

    /** The built-in rate is 100 points per currency unit, so a 100-point balance is worth one unit. */
    public function test_a_live_discount_reward_shows_what_the_balance_is_worth(): void {
        $this->seed_discount_reward( Status::ACTIVE );
        $this->create_campaign();

        $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

        $this->assertStringContainsString(
            "That's worth " . html_entity_decode( wp_strip_all_tags( wc_price( 1 ) ) ) . ' in rewards.',
            html_entity_decode( wp_strip_all_tags( $this->sent[0]['message'] ), ENT_QUOTES )
        );
    }

    public function test_a_paused_discount_reward_shows_no_value(): void {
        $this->seed_discount_reward( Status::PAUSED );
        $this->create_campaign();

        $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

        $this->assertStringNotContainsString( 'worth', $this->sent[0]['message'] );
    }

    public function test_no_discount_reward_shows_no_value(): void {
        $this->create_campaign();

        $this->transition( $this->create_order( $this->customer_with_email(), [ 100 ] ), 'completed' );

        $this->assertStringNotContainsString( 'worth', $this->sent[0]['message'] );
    }

    public function test_a_template_without_an_award_falls_back_to_the_generic_line(): void {
        $email = new PointsEarnedEmail();
        $email->set_object( new \WC_Order() );

        $html = $email->get_content_html();

        $this->assertStringContainsString( 'New points have been added to your account.', $html );
        $this->assertStringNotContainsString( 'View your points', $html );
    }

    /** The built-in cart-discount reward, as the installer seeds it but at $status; the scratch tables start empty. */
    private function seed_discount_reward( string $status ): void {
        ( new RewardRepository() )->seed_builtin(
            new Reward( null, 'Cart discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, $status, 0, null, Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ) )
        );
    }

    private function customer_with_email(): int {
        return $this->create_customer( 'yl_it_' . wp_generate_password( 8, false ) . '@example.com' );
    }
}
