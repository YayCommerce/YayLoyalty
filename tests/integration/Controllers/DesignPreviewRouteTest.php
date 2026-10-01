<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherPreview;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/**
 * POST /design/preview draws the storefront launcher from the real templates with the unsaved design,
 * saves nothing, and leaves the storefront's own markup (closed, skeleton rows) untouched.
 */
final class DesignPreviewRouteTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    /** @var array */
    private $saved_design = [];

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->saved_design = DesignHelper::get_design();

        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $this->assertNotEmpty( $admins, 'The site needs one administrator to act as the caller.' );

        wp_set_current_user( (int) $admins[0] );
        rest_get_server();
    }

    protected function tearDown(): void {
        DesignHelper::update_design( $this->saved_design );
        $this->cleanup_orders();
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_member_preview_draws_unsaved_label_colour_and_sample_shopper(): void {
        $design                                      = $this->saved_design;
        $design['storefront_text']['launcher_label'] = 'Nordvik Points';
        $design['launcher']['brand_color']           = '#aa3300';

        $preview = $this->preview( $design, 'member' );

        $this->assertStringContainsString( 'Nordvik Points', $preview['collapsed'] );
        $this->assertStringContainsString( '--primary:#aa3300', $preview['collapsed'] );
        $this->assertStringContainsString( 'aria-expanded="false"', $preview['collapsed'] );
        $this->assertStringContainsString( 'data-state="open"', $preview['expanded'] );
        $this->assertStringContainsString( LauncherCopy::points( LauncherPreview::SAMPLE_BALANCE ), $preview['expanded'] );
        $this->assertStringContainsString( 'order #4207', $preview['expanded'], 'Sample activity is printed, not left as skeletons.' );
        $this->assertStringContainsString( 'storefront/launcher.css', $preview['expanded'] );
        $this->assertStringNotContainsString( '<script', $preview['expanded'] );
    }

    public function test_preview_saves_nothing(): void {
        $design                                      = $this->saved_design;
        $design['storefront_text']['launcher_label'] = 'Not saved';

        $this->preview( $design, 'member' );

        $this->assertSame( $this->saved_design['storefront_text']['launcher_label'], DesignHelper::get_design()['storefront_text']['launcher_label'] );
    }

    public function test_guest_preview_draws_the_signed_out_panel_with_live_rates(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ] );

        $expanded = $this->preview( $this->saved_design, 'guest' )['expanded'];

        $this->assertStringContainsString( 'Sign in', $expanded );
        $this->assertStringContainsString( LauncherCopy::rate( 3.0 ), $expanded );
        $this->assertStringNotContainsString( 'Recent activity', $expanded );
    }

    public function test_rejects_a_missing_design(): void {
        $request = new \WP_REST_Request( 'POST', '/yayloyalty/v1/design/preview' );
        $request->set_body_params( [ 'viewer' => 'member' ] );

        $this->assertSame( 400, rest_do_request( $request )->get_status() );
    }

    public function test_shopper_cannot_preview(): void {
        wp_set_current_user( $this->create_customer() );

        $this->assertSame( 403, $this->request( $this->saved_design, 'member' )->get_status() );
    }

    private function preview( array $design, string $viewer ): array {
        $response = $this->request( $design, $viewer );

        $this->assertSame( 200, $response->get_status() );

        return $response->get_data();
    }

    private function request( array $design, string $viewer ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'POST', '/yayloyalty/v1/design/preview' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( compact( 'design', 'viewer' ) ) );

        return rest_do_request( $request );
    }
}
