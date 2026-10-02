<?php
namespace YayLoyalty\Tests\Integration\Emails;

use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayLoyalty\Integrations\YayMail\PointsEarnedShortcodes;
use YayLoyalty\Integrations\YayMail\YayMailIntegration;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** A licensed YayMail lists the Points earned email and fills its shortcodes from PointsEarnedEmail; skipped without one. */
final class YayMailPointsEarnedTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        if ( ! YayMailIntegration::is_yaymail_active() || ! function_exists( 'yaymail_get_emails' ) ) {
            $this->markTestSkipped( 'Needs a licensed YayMail active on this site.' );
        }

        $this->require_woocommerce();
        remove_filter( 'woocommerce_email_enabled_' . PointsEarnedEmail::ID, '__return_false' );
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_yaymail_lists_points_earned_under_yayloyalty(): void {
        $listed = null;

        foreach ( yaymail_get_emails() as $email ) {
            if ( PointsEarnedEmail::ID === $email->get_id() ) {
                $listed = $email;
            }
        }

        $this->assertNotNull( $listed );
        $this->assertSame( 'yayloyalty', $listed->get_source()['plugin_id'] );
    }

    /** The editor and its preview render with `is_sample` and no email, so the shortcodes show the sample award. */
    public function test_sample_renders_show_the_sample_award(): void {
        $render_data = [ 'is_sample' => true ];

        $this->assertSame( '50', $this->shortcode( 'points', $render_data ) );
        $this->assertSame( esc_html( SettingsHelper::points_label( 50 ) ), $this->shortcode( 'points_label', $render_data ) );
        $this->assertSame( '150', $this->shortcode( 'balance', $render_data ) );
        $this->assertStringContainsString( '<strong>50 ' . SettingsHelper::points_label( 50 ) . '</strong>', $this->shortcode( 'earned_text', $render_data ) );
    }

    /** A real send passes the WooCommerce template args, so the shortcodes read the award being sent. */
    public function test_real_sends_read_the_award_being_sent(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();
        update_user_meta( $user_id, 'first_name', 'Anna' );

        $order       = $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );
        $render_data = [ 'email' => WC()->mailer()->get_emails()[ PointsEarnedEmail::ID ] ];

        $this->assertSame( 'Anna', $this->shortcode( 'customer_name', $render_data ) );
        $this->assertSame( (string) $order->get_order_number(), $this->shortcode( 'order_number', $render_data ) );
        $this->assertStringContainsString( 'for order #' . $order->get_order_number(), $this->shortcode( 'earned_text', $render_data ) );
    }

    /** YayMail's subject filter renders a real send with empty render_data; it must not swap in the sample award. */
    public function test_subject_renders_read_the_award_being_sent(): void {
        $this->create_campaign();
        $user_id = $this->customer_with_email();
        update_user_meta( $user_id, 'first_name', 'Anna' );

        $order = $this->transition( $this->create_order( $user_id, [ 100 ] ), 'completed' );

        $this->assertSame( 'Anna', $this->shortcode( 'customer_name', [] ) );
        $this->assertSame( (string) $order->get_order_number(), $this->shortcode( 'order_number', [] ) );
    }

    public function test_every_shortcode_is_registered_for_the_points_earned_email(): void {
        $names = array_column( PointsEarnedShortcodes::get_instance()->get_shortcodes(), 'name' );

        $this->assertContains( 'yaymail_yayloyalty_earned_text', $names );
        $this->assertContains( 'yaymail_yayloyalty_points_url', $names );
        $this->assertSame( [ PointsEarnedEmail::ID ], PointsEarnedShortcodes::get_instance()->available_email_ids );
    }

    /** @param array<string, mixed> $render_data */
    private function shortcode( string $key, array $render_data ): string {
        return PointsEarnedShortcodes::get_instance()->render(
            [
                'render_data'    => $render_data,
                'yayloyalty_key' => $key,
            ]
        );
    }
}
