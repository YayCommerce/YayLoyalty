<?php
namespace YayLoyalty\Tests\Integration\Emails;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Tests\Integration\WooCommerceEmailsFixture;
use YayLoyalty\Engine\Emails\LoyaltyEmail;
use YayLoyalty\Engine\Emails\PointsEarnedEmail;
use YayLoyalty\Engine\Emails\PointsExpiredEmail;
use YayLoyalty\Engine\Emails\PointsExpiringSoonEmail;

/**
 * The Lite emails are real WooCommerce emails: registered through
 * `woocommerce_email_classes`, grouped, and rendered from our own templates.
 * Default on/off is read from a fresh instance with its WooCommerce option
 * removed, so a merchant's saved choice on this site never decides the test.
 */
final class LiteEmailsRegistrationTest extends TestCase {

    use WooCommerceEmailsFixture;

    private const EMAILS = [
        'yayloyalty_points_earned'        => [
            'class'                 => PointsEarnedEmail::class,
            'is_enabled_by_default' => true,
            'body'                  => 'New points have been added to your account.',
        ],
        'yayloyalty_points_expiring_soon' => [
            'class'                 => PointsExpiringSoonEmail::class,
            'is_enabled_by_default' => true,
            'body'                  => 'Some of your loyalty points will expire soon.',
        ],
        'yayloyalty_points_expired'       => [
            'class'                 => PointsExpiredEmail::class,
            'is_enabled_by_default' => false,
            'body'                  => 'Some of your loyalty points have expired',
        ],
    ];

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce_mailer();

        foreach ( array_keys( self::EMAILS ) as $id ) {
            $this->snapshot_options( "woocommerce_{$id}_settings" );
            delete_option( "woocommerce_{$id}_settings" );
        }
    }

    protected function tearDown(): void {
        $this->restore_options();

        parent::tearDown();
    }

    public function test_each_lite_email_is_registered_under_its_id(): void {
        $emails = WC()->mailer()->get_emails();

        foreach ( self::EMAILS as $id => $expected ) {
            $this->assertArrayHasKey( $id, $emails );
            $this->assertInstanceOf( $expected['class'], $emails[ $id ] );
            $this->assertInstanceOf( LoyaltyEmail::class, $emails[ $id ] );
            $this->assertSame( $id, $emails[ $id ]->id );
        }
    }

    public function test_lite_emails_are_customer_emails_in_the_points_group(): void {
        $emails = WC()->mailer()->get_emails();

        foreach ( array_keys( self::EMAILS ) as $id ) {
            $this->assertTrue( $emails[ $id ]->is_customer_email() );
            $this->assertSame( 'yayloyalty_points', $emails[ $id ]->email_group );
            $this->assertSame( 'Loyalty: Points', $emails[ $id ]->get_email_group_title() );
        }
    }

    public function test_enabled_defaults_are_on_on_off(): void {
        foreach ( self::EMAILS as $expected ) {
            $this->assertSame( $expected['is_enabled_by_default'], ( new $expected['class']() )->is_enabled(), $expected['class'] );
        }
    }

    /** Its own body sentence proves each email reached its own template; no tags proves plain is not the HTML one. */
    public function test_each_lite_email_renders_html_and_plain_from_its_own_templates(): void {
        foreach ( self::EMAILS as $expected ) {
            $email = new $expected['class']();
            $html  = $email->get_content_html();
            $plain = $email->get_content_plain();

            $this->assertStringContainsString( $email->get_default_heading(), $html, $expected['class'] );
            $this->assertStringContainsString( $expected['body'], $html, $expected['class'] );
            $this->assertStringContainsString( $expected['body'], $plain, $expected['class'] );
            $this->assertStringNotContainsString( '<p>', $plain, $expected['class'] );
        }
    }
}
