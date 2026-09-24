<?php
namespace YayLoyalty\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Helpers\EmailsHelper;
use YayLoyalty\Helpers\SettingsHelper;

/**
 * The parts of EmailsHelper that need no WooCommerce: the weekly throttle, kept
 * in plugin settings, and the sender card when WooCommerce is inactive. Listing
 * and toggling emails go through WooCommerce's mailer, so the integration suite
 * covers those.
 */
class EmailsHelperTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['yayloyalty_test_options'] = [];
    }

    /** Without WooCommerce there is no mailer to ask: no emails, blank sender fields, never a fatal. */
    public function test_get_emails_is_woocommerce_inactive_safe(): void {
        $emails = EmailsHelper::get_emails();

        $this->assertSame( [], $emails['groups'] );
        $this->assertSame( '', $emails['sending']['from_name'] );
        $this->assertSame( '', $emails['sending']['from_address'] );
        $this->assertStringContainsString( 'page=wc-settings', $emails['sending']['wc_emails_url'] );
        $this->assertStringContainsString( 'tab=email', $emails['sending']['wc_emails_url'] );
    }

    public function test_throttle_defaults_to_three(): void {
        $this->assertSame( 3, EmailsHelper::get_emails()['throttle_per_week'] );
    }

    public function test_update_emails_persists_the_throttle_in_plugin_settings(): void {
        $this->assertSame( 10, EmailsHelper::update_emails( [ 'throttle_per_week' => 10 ] )['throttle_per_week'] );
        $this->assertSame( 10, SettingsHelper::get_settings()['emails']['throttle_per_week'] );
    }

    public function test_update_emails_clamps_a_negative_throttle_to_zero(): void {
        $this->assertSame( 0, EmailsHelper::update_emails( [ 'throttle_per_week' => -5 ] )['throttle_per_week'] );
    }

    /** A throttle-only write must not reset the rest of the plugin settings. */
    public function test_update_emails_keeps_other_stored_settings(): void {
        SettingsHelper::update_settings(
            [
                'general'  => [ 'points_label_singular' => 'Star' ],
                'advanced' => [ 'adjustment_ceiling' => 50 ],
            ]
        );

        EmailsHelper::update_emails( [ 'throttle_per_week' => 5 ] );

        $settings = SettingsHelper::get_settings();
        $this->assertSame( 'Star', $settings['general']['points_label_singular'] );
        $this->assertSame( 50, $settings['advanced']['adjustment_ceiling'] );
        $this->assertSame( 5, $settings['emails']['throttle_per_week'] );
    }

    /** The patch writes only what it carries: no throttle sent, stored throttle kept. */
    public function test_update_emails_without_a_throttle_leaves_it_untouched(): void {
        EmailsHelper::update_emails( [ 'throttle_per_week' => 7 ] );

        $this->assertSame( 7, EmailsHelper::update_emails( [ 'emails' => [] ] )['throttle_per_week'] );
    }
}
