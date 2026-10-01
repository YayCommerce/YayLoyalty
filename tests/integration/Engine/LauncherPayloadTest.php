<?php
namespace YayLoyalty\Tests\Integration\Engine;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/**
 * Test LauncherPayload::build() for guest and signed-in states.
 *
 * Guest payload: member is null, no nonce/activity keys, earn rules from live campaigns.
 * Member payload: balance equals customers.balance, nonce/activity provided.
 * Paused signup campaign → signup rule is null.
 */
final class LauncherPayloadTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private int $customer_user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();

        $this->customer_user_id = (int) wp_insert_user(
            [
                'user_login' => 'payload-test-customer',
                'user_email' => 'payload-test@example.com',
                'user_pass'  => wp_generate_password(),
                'role'       => 'customer',
            ]
        );
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        if ( $this->customer_user_id > 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $this->customer_user_id );
        }

        parent::tearDown();
    }

    public function test_guest_payload_has_no_member(): void {
        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertNull( $result['member'], 'Guest payload should have no member' );
    }

    public function test_member_payload_has_balance(): void {
        $this->set_balance( $this->customer_user_id, 500 );

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, $this->customer_user_id );

        $this->assertIsArray( $result['member'] );
        $this->assertSame( 500, $result['member']['balance'] );
    }

    public function test_member_with_no_customer_record_has_zero_balance(): void {
        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, $this->customer_user_id );

        $this->assertIsArray( $result['member'] );
        $this->assertSame( 0, $result['member']['balance'] );
    }

    public function test_earn_rules_from_live_campaigns(): void {
        // Create a live purchase campaign.
        global $wpdb;
        $wpdb->insert(
            esc_sql( \YayLoyalty\Utils\Table::name( 'campaigns' ) ),
            [
                'trigger_type' => CampaignTrigger::PURCHASE,
                'slug'         => 'purchase-live',
                'name'         => 'Live Purchase',
                'status'       => Status::ACTIVE,
                'priority'     => 0,
                'is_builtin'   => 1,
                'config'       => wp_json_encode( [ 'points_per_unit' => 2.5 ] ),
                'conditions'   => wp_json_encode( [] ),
                'starts_at'    => null,
                'ends_at'      => null,
                'created_at'   => gmdate( 'Y-m-d H:i:s' ),
                'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        ( new CampaignRepository() )->flush_cache();

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertSame( 2.5, $result['earn'][ CampaignTrigger::PURCHASE ] );
    }

    public function test_paused_signup_campaign_returns_null(): void {
        // Create a paused signup campaign.
        global $wpdb;
        $wpdb->insert(
            esc_sql( \YayLoyalty\Utils\Table::name( 'campaigns' ) ),
            [
                'trigger_type' => CampaignTrigger::SIGNUP,
                'slug'         => 'signup-paused',
                'name'         => 'Paused Signup',
                'status'       => Status::PAUSED,
                'priority'     => 0,
                'is_builtin'   => 1,
                'config'       => wp_json_encode( [ 'points' => 100 ] ),
                'conditions'   => wp_json_encode( [] ),
                'starts_at'    => null,
                'ends_at'      => null,
                'created_at'   => gmdate( 'Y-m-d H:i:s' ),
                'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        ( new CampaignRepository() )->flush_cache();

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertNull( $result['earn'][ CampaignTrigger::SIGNUP ] );
    }

    public function test_guest_has_no_estimate_without_purchase_rate(): void {
        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertNull( $result['estimate'], 'Guest without purchase rate should have no estimate' );
    }

    public function test_payload_has_required_keys(): void {
        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertArrayHasKey( 'launcher', $result );
        $this->assertArrayHasKey( 'text', $result );
        $this->assertArrayHasKey( 'hide_mobile', $result );
        $this->assertArrayHasKey( 'earn', $result );
        $this->assertArrayHasKey( 'redeem', $result );
        $this->assertArrayHasKey( 'member', $result );
        $this->assertArrayHasKey( 'estimate', $result );
        $this->assertArrayHasKey( 'register', $result );
        $this->assertArrayHasKey( 'checkout_url', $result );
        $this->assertArrayHasKey( 'account_url', $result );
    }

    public function test_earn_has_three_triggers(): void {
        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertArrayHasKey( CampaignTrigger::PURCHASE, $result['earn'] );
        $this->assertArrayHasKey( CampaignTrigger::SIGNUP, $result['earn'] );
        $this->assertArrayHasKey( CampaignTrigger::REVIEW, $result['earn'] );
    }

    public function test_member_has_balance_and_worth(): void {
        $this->set_balance( $this->customer_user_id, 100 );

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, $this->customer_user_id );

        $this->assertArrayHasKey( 'balance', $result['member'] );
        $this->assertArrayHasKey( 'worth', $result['member'] );
        $this->assertSame( 100, $result['member']['balance'] );
    }

    public function test_zero_balance_has_no_worth(): void {
        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, $this->customer_user_id );

        $this->assertSame( 0, $result['member']['balance'] );
        $this->assertNull( $result['member']['worth'] );
    }

    public function test_register_myaccount_when_registration_enabled(): void {
        update_option( 'woocommerce_enable_myaccount_registration', 'yes' );

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertSame( 'account', $result['register'] );

        delete_option( 'woocommerce_enable_myaccount_registration' );
    }

    public function test_register_checkout_when_checkout_signup_enabled(): void {
        update_option( 'woocommerce_enable_myaccount_registration', 'no' );
        update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertSame( 'checkout', $result['register'] );

        delete_option( 'woocommerce_enable_myaccount_registration' );
        delete_option( 'woocommerce_enable_signup_and_login_from_checkout' );
    }

    public function test_register_null_when_no_signup_enabled(): void {
        update_option( 'woocommerce_enable_myaccount_registration', 'no' );
        update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        $this->assertNull( $result['register'] );

        delete_option( 'woocommerce_enable_myaccount_registration' );
        delete_option( 'woocommerce_enable_signup_and_login_from_checkout' );
    }

    public function test_product_estimate_on_product_page(): void {
        // Create a purchase campaign with 2 points per unit.
        global $wpdb;
        $wpdb->insert(
            esc_sql( \YayLoyalty\Utils\Table::name( 'campaigns' ) ),
            [
                'trigger_type' => CampaignTrigger::PURCHASE,
                'slug'         => 'purchase-estimate',
                'name'         => 'Estimate Purchase',
                'status'       => Status::ACTIVE,
                'priority'     => 0,
                'is_builtin'   => 1,
                'config'       => wp_json_encode( [ 'points_per_unit' => 2 ] ),
                'conditions'   => wp_json_encode( [] ),
                'starts_at'    => null,
                'ends_at'      => null,
                'created_at'   => gmdate( 'Y-m-d H:i:s' ),
                'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        ( new CampaignRepository() )->flush_cache();

        // Create a simple product with a price.
        $product_id = $this->create_product();
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            $this->markTestSkipped( 'Could not create a product.' );
        }

        // Simulate being on the product page.
        global $wp_query;
        $wp_query = new \WP_Query(  // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required to test product page context.
            [
                'post_type' => 'product',
                'p'         => $product_id,
            ]
        );

        $payload_builder = new LauncherPayload(
            new CampaignRepository(),
            new RewardRepository(),
            new CustomerRepository()
        );

        $design = $this->dummy_design();
        $result = $payload_builder->build( $design, null );

        // Calculate what the engine would award.
        $expected = PointsEngine::build( new CampaignRepository() )
            ->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( $product ) )
            ->get_points();

        $this->assertSame( $expected, $result['estimate'], 'Product estimate should match PointsEngine evaluation' );
    }

    /**
     * Minimal design for testing. Ensure it has the required keys.
     */
    private function dummy_design(): array {
        return [
            'launcher'        => [
                'show_panel_button' => true,
                'button_text'       => 'My Points',
            ],
            'storefront_text' => [
                'earn_rate_line' => 'Earn {points} points per {amount} spent',
            ],
            'visibility'      => [
                'scope'          => 'all_pages',
                'hide_for_roles' => [],
                'hide_on_mobile' => false,
            ],
        ];
    }
}
