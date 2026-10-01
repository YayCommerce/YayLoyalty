<?php
namespace YayLoyalty\Tests\Integration\Engine;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Storefront\Launcher;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/**
 * Test Launcher rendering for various visibility conditions.
 *
 * Guest: render if program enabled; customer: render if not opted out, role not excluded, visibility allows.
 * Signed-in markup includes data-nonce and data-activity-url; guest markup does not.
 * Style attribute carries theme variables including light/dark primary foreground.
 */
final class LauncherRenderTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private array $original_settings = [];
    private array $original_design = [];
    private int $customer_user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();

        // Save original settings and design for restoration.
        $this->original_settings = SettingsHelper::get_settings();
        $this->original_design   = DesignHelper::get_design();

        // Create a test customer.
        $this->customer_user_id = (int) wp_insert_user(
            [
                'user_login' => 'render-test-customer',
                'user_email' => 'render-test@example.com',
                'user_pass'  => wp_generate_password(),
                'role'       => 'customer',
            ]
        );
    }

    protected function tearDown(): void {
        // Restore original settings and design.
        SettingsHelper::update_settings( $this->original_settings );
        DesignHelper::update_design( $this->original_design );

        if ( $this->customer_user_id > 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $this->customer_user_id );
        }

        $this->cleanup_orders();
        parent::tearDown();
    }

    public function test_guest_with_program_enabled_renders_launcher(): void {
        $this->enable_program();

        wp_set_current_user( 0 );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'class="yayloyalty-launcher"', $html, 'Guest with program enabled should render launcher' );
    }

    public function test_guest_with_program_disabled_renders_nothing(): void {
        $this->disable_program();

        wp_set_current_user( 0 );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringNotContainsString( 'yayloyalty-launcher', $html, 'Guest with program disabled should not render' );
    }

    public function test_customer_with_excluded_role_renders_nothing(): void {
        $this->enable_program();

        // Add customer role to excluded roles.
        $settings = SettingsHelper::get_settings();
        $settings['general']['excluded_roles'][] = 'customer';
        SettingsHelper::update_settings( $settings );

        wp_set_current_user( $this->customer_user_id );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringNotContainsString( 'yayloyalty-launcher', $html, 'Customer with excluded role should not render' );
    }

    public function test_customer_opted_out_renders_nothing(): void {
        $this->enable_program();

        // Mark customer as opted out.
        update_user_meta( $this->customer_user_id, ProgramGate::META_OPTED_OUT, 1 );

        wp_set_current_user( $this->customer_user_id );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringNotContainsString( 'yayloyalty-launcher', $html, 'Opted-out customer should not render' );
    }

    public function test_customer_in_hide_for_roles_renders_nothing(): void {
        $this->enable_program();

        // Add customer role to hide_for_roles.
        $design = DesignHelper::get_design();
        $design['visibility']['hide_for_roles'][] = 'customer';
        DesignHelper::update_design( $design );

        wp_set_current_user( $this->customer_user_id );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringNotContainsString( 'yayloyalty-launcher', $html, 'Customer in hide_for_roles should not render' );
    }

    public function test_signed_in_customer_has_nonce_and_activity_url(): void {
        $this->enable_program();

        wp_set_current_user( $this->customer_user_id );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( 'data-nonce', $html, 'Signed-in customer markup should have data-nonce' );
        $this->assertStringContainsString( 'data-activity-url', $html, 'Signed-in customer markup should have data-activity-url' );
        $this->assertStringContainsString( '/me/activity', $html, 'Activity URL should contain /me/activity' );
    }

    public function test_guest_has_no_nonce_or_activity_url(): void {
        $this->enable_program();

        wp_set_current_user( 0 );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringNotContainsString( 'data-nonce', $html, 'Guest markup should not have data-nonce' );
        $this->assertStringNotContainsString( 'data-activity-url', $html, 'Guest markup should not have data-activity-url' );
    }

    public function test_light_brand_color_has_dark_foreground(): void {
        $this->enable_program();

        // Set a light brand color (yellow).
        $design = DesignHelper::get_design();
        $design['launcher']['brand_color'] = '#ffff00';
        DesignHelper::update_design( $design );

        wp_set_current_user( 0 );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( '--primary-foreground:#171719', $html, 'Light brand color should have dark (#171719) foreground' );
    }

    public function test_dark_brand_color_has_light_foreground(): void {
        $this->enable_program();

        // Set a dark brand color (dark blue).
        $design = DesignHelper::get_design();
        $design['launcher']['brand_color'] = '#1f6fb2';
        DesignHelper::update_design( $design );

        wp_set_current_user( 0 );

        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();
        $html = ob_get_clean();

        $this->assertStringContainsString( '--primary-foreground:#ffffff', $html, 'Dark brand color should have light (#ffffff) foreground' );
    }

    /** Test case MEM-07 / D6: a signed-in shopper already has the account the signup campaign pays for. */
    public function test_member_panel_has_no_signup_row(): void {
        $this->enable_program();
        $this->create_campaign( CampaignTrigger::SIGNUP, [ 'points' => 100 ] );
        $this->create_campaign( CampaignTrigger::REVIEW, [ 'points' => 50 ] );

        wp_set_current_user( $this->customer_user_id );
        $html = $this->rendered();

        $this->assertStringContainsString( 'Write a review', $html );
        $this->assertStringNotContainsString( 'Create an account', $html );
    }

    /** Test case EARN-12 / D4: the advertised rate states its campaign's minimum order. */
    public function test_guest_panel_states_purchase_minimum(): void {
        $this->enable_program();
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 1, 'min_subtotal' => 50 ] );

        wp_set_current_user( 0 );
        $html = $this->rendered();

        $this->assertStringContainsString( 'or more', $html );
    }

    private function rendered(): string {
        $launcher = new Launcher();
        $launcher->prepare();

        ob_start();
        $launcher->render();

        return (string) ob_get_clean();
    }

    private function enable_program(): void {
        $settings = SettingsHelper::get_settings();
        $settings['general']['enabled'] = true;
        SettingsHelper::update_settings( $settings );
    }

    private function disable_program(): void {
        $settings = SettingsHelper::get_settings();
        $settings['general']['enabled'] = false;
        SettingsHelper::update_settings( $settings );
    }
}
