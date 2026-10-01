<?php
namespace YayLoyalty\Tests\Integration\Engine;

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\DesignSchema;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/**
 * Test LauncherCopy formatting methods.
 *
 * earn_rate: blank template falls back to DesignSchema default.
 */
final class LauncherCopyTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_earn_rate_blank_template_uses_default(): void {
        $rate             = 2.5;
        $blank_template   = '';
        $default_template = DesignSchema::get_default_design()['storefront_text']['earn_rate_line'];

        $result = LauncherCopy::earn_rate( $blank_template, $rate );

        // The result should contain the formatted rate and 1 dollar (or local currency).
        $this->assertStringContainsString( '2.5', $result, 'Result should contain the rate' );
        $this->assertStringContainsString( '$', $result, 'Result should contain currency symbol' );

        // Verify it matches what we'd get with the default template.
        $expected = LauncherCopy::earn_rate( $default_template, $rate );
        $this->assertSame( $expected, $result, 'Blank template should produce same output as default' );
    }

    public function test_earn_rate_whitespace_only_template_uses_default(): void {
        $rate = 1.0;
        $whitespace_template = '   ';

        $result = LauncherCopy::earn_rate( $whitespace_template, $rate );

        // Should fall back to default and format the rate.
        $default_template = DesignSchema::get_default_design()['storefront_text']['earn_rate_line'];
        $expected = LauncherCopy::earn_rate( $default_template, $rate );

        $this->assertSame( $expected, $result, 'Whitespace-only template should use default' );
    }

    public function test_earn_rate_custom_template_preserved(): void {
        $rate = 1.5;
        $custom_template = 'Get {points} loyalty tokens for each {amount} you spend!';

        $result = LauncherCopy::earn_rate( $custom_template, $rate );

        // Should use the custom template, not the default.
        $this->assertStringContainsString( 'loyalty tokens', $result, 'Custom template text should be preserved' );
        $this->assertStringContainsString( '1.5', $result, 'Rate should be substituted' );
    }

    public function test_points_formatting(): void {
        $result = LauncherCopy::points( 1234 );

        $this->assertIsString( $result, 'points() should return a string' );
        // Should be formatted with thousands separator or as locale appropriate.
        $this->assertNotEmpty( $result );
    }

    public function test_signed_points_positive(): void {
        $result = LauncherCopy::signed_points( 100 );

        $this->assertStringStartsWith( '+', $result, 'Positive points should start with +' );
        $this->assertStringContainsString( '100', $result, 'Should contain the point value' );
    }

    public function test_signed_points_negative(): void {
        $result = LauncherCopy::signed_points( -100 );

        $this->assertStringStartsWith( '−', $result, 'Negative points should start with minus sign' );
        $this->assertStringContainsString( '100', $result, 'Should contain the absolute point value' );
    }

    public function test_rate_formatting(): void {
        $result = LauncherCopy::rate( 1.5 );

        $this->assertIsString( $result, 'rate() should return a string' );
        $this->assertStringContainsString( '1.5', $result, 'Should contain the rate value' );
    }

    public function test_money_formatting(): void {
        $result = LauncherCopy::money( 10.50 );

        $this->assertIsString( $result, 'money() should return a string' );
        // Should contain currency symbol (likely $ or formatted version) and a digit.
        $this->assertNotEmpty( $result, 'Should have formatted output' );
        // Check that it contains at least one digit (locale and WC config specific formatting).
        $this->assertMatchesRegularExpression( '/\d/', $result, 'Should contain a digit' );
    }

    public function test_redeem_rate(): void {
        $result = LauncherCopy::redeem_rate( 100.0 );

        $this->assertIsString( $result, 'redeem_rate() should return a string' );
        $this->assertStringContainsString( '100', $result, 'Should contain the rate' );
        $this->assertStringContainsString( '=', $result, 'Should contain equals sign' );
    }
}
