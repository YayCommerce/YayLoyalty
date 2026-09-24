<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Access\Capabilities;
use YayLoyalty\Helpers\EmailsHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Tests\Integration\WooCommerceEmailsFixture;

/**
 * GET/POST /emails read and write WooCommerce's own per-email "enabled"
 * option, so our page and WooCommerce's Emails screen always agree. Every
 * option a test touches is snapshotted and restored.
 */
final class EmailsRouteTest extends TestCase {

    use WooCommerceEmailsFixture;

    private const ROUTE = '/yayloyalty/v1/emails';

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce_mailer();

        $this->snapshot_options( SettingsHelper::OPTION_SETTINGS );
        foreach ( EmailsHelper::registered_emails() as $email ) {
            $this->snapshot_options( $email->get_option_key() );
        }

        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $this->assertNotEmpty( $admins, 'The site needs one administrator to act as the caller.' );

        wp_set_current_user( (int) $admins[0] );
        rest_get_server(); // Fires rest_api_init, which is what registers the plugin's routes.
    }

    protected function tearDown(): void {
        $this->restore_options();
        $this->start_new_request();
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_get_lists_every_registered_loyalty_email_with_its_woocommerce_state(): void {
        $items = $this->items( $this->get()->get_data() );

        $this->assertCount( count( EmailsHelper::registered_emails() ), $items );

        foreach ( EmailsHelper::registered_emails() as $email ) {
            $this->assertSame( $email->is_enabled(), $items[ $email->id ]['enabled'], $email->id );
            $this->assertSame( 'customer', $items[ $email->id ]['recipient'], $email->id );
            $this->assertArrayNotHasKey( 'isFree', $items[ $email->id ] );
        }
    }

    public function test_edit_url_opens_the_emails_own_woocommerce_section(): void {
        $items = $this->items( $this->get()->get_data() );

        $this->assertStringContainsString( 'section=yayloyalty_points_earned', $items['yayloyalty_points_earned']['edit_url'] );
    }

    public function test_post_writes_woocommerces_enabled_option_and_leaves_others_alone(): void {
        $this->set_enabled_in_woocommerce( 'yayloyalty_points_earned', 'yes' );
        $this->set_enabled_in_woocommerce( 'yayloyalty_points_expiring_soon', 'yes' );

        $response = $this->post( [ 'emails' => [ 'yayloyalty_points_earned' => false ] ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'no', get_option( 'woocommerce_yayloyalty_points_earned_settings' )['enabled'] );
        $this->assertSame( 'yes', get_option( 'woocommerce_yayloyalty_points_expiring_soon_settings' )['enabled'] );
        $this->assertFalse( $this->items( $response->get_data() )['yayloyalty_points_earned']['enabled'] );
    }

    /** The other direction: a merchant turns an email on in WooCommerce's screen, our page shows it on. */
    public function test_get_reflects_a_change_made_in_woocommerce(): void {
        $this->set_enabled_in_woocommerce( 'yayloyalty_points_expired', 'yes' );

        $this->assertTrue( $this->items( $this->get()->get_data() )['yayloyalty_points_expired']['enabled'] );
    }

    public function test_post_ignores_unknown_ids(): void {
        $response = $this->post( [ 'emails' => [ 'not_a_loyalty_email' => true ] ] );

        $this->assertSame( 200, $response->get_status() );
        foreach ( EmailsHelper::registered_emails() as $email ) {
            $this->assertSame( $this->saved_options[ $email->get_option_key() ], get_option( $email->get_option_key(), null ), $email->id );
        }
    }

    public function test_post_without_emails_is_rejected(): void {
        $this->assertSame( 400, $this->post( [ 'throttle_per_week' => 2 ] )->get_status() );
    }

    /** Checked before any write: a rolled-back transaction does not undo a persistent object cache. */
    public function test_post_with_a_non_numeric_throttle_is_rejected_before_any_write(): void {
        $this->set_enabled_in_woocommerce( 'yayloyalty_points_earned', 'yes' );
        $throttle = SettingsHelper::get_settings()['emails']['throttle_per_week'];

        $response = $this->post(
            [
                'emails'            => [ 'yayloyalty_points_earned' => false ],
                'throttle_per_week' => 'abc',
            ]
        );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'yes', get_option( 'woocommerce_yayloyalty_points_earned_settings' )['enabled'] );
        $this->assertSame( $throttle, SettingsHelper::get_settings()['emails']['throttle_per_week'] );
    }

    public function test_response_keys_are_snake_case(): void {
        $data  = $this->get()->get_data();
        $group = $data['groups'][0];

        $this->assertArrayHasKey( 'throttle_per_week', $data );
        $this->assertArrayHasKey( 'group_label', $group );
        $this->assertArrayNotHasKey( 'groupLabel', $group );
        $this->assertArrayHasKey( 'edit_url', $group['items'][0] );
    }

    public function test_post_saves_the_throttle_into_plugin_settings_without_resetting_them(): void {
        SettingsHelper::update_settings( [ 'general' => [ 'points_label_singular' => 'Star' ] ] );

        $response = $this->post(
            [
                'emails'            => [],
                'throttle_per_week' => -4,
            ]
        );

        $this->assertSame( 0, $response->get_data()['throttle_per_week'] );
        $this->assertSame( 0, SettingsHelper::get_settings()['emails']['throttle_per_week'] );
        $this->assertSame( 'Star', SettingsHelper::get_settings()['general']['points_label_singular'] );
    }

    /** Refusals are 403 naming the missing capability (AbstractRestController::forbidden()). */
    public function test_reading_needs_view_and_writing_needs_manage_settings(): void {
        wp_set_current_user( 0 );

        $read  = $this->get();
        $write = $this->post( [ 'emails' => [] ] );

        $this->assertSame( 403, $read->get_status() );
        $this->assertSame( Capabilities::VIEW, $read->get_data()['data']['capability'] );
        $this->assertSame( 403, $write->get_status() );
        $this->assertSame( Capabilities::MANAGE_SETTINGS, $write->get_data()['data']['capability'] );
    }

    /**
     * Writes WooCommerce's option the way its own Emails screen does, then drops
     * the mailer so the next read builds fresh emails, as the next request would.
     */
    private function set_enabled_in_woocommerce( string $id, string $yes_or_no ): void {
        $settings            = get_option( "woocommerce_{$id}_settings", [] );
        $settings['enabled'] = $yes_or_no;
        update_option( "woocommerce_{$id}_settings", $settings );

        $this->start_new_request();
    }

    /**
     * WC_Emails is a per-request singleton whose emails read their settings once;
     * clearing it mimics the next request. The old instances stay hooked for the
     * rest of the run, harmless while nothing sends mail.
     */
    private function start_new_request(): void {
        $instance = new \ReflectionProperty( \WC_Emails::class, 'instance' );
        if ( PHP_VERSION_ID < 80100 ) {
            $instance->setAccessible( true ); // Needed before PHP 8.1; deprecated from 8.5.
        }
        $instance->setValue( null, null );
    }

    /** @return array<string, array<string, mixed>> Items keyed by email id. */
    private function items( array $response ): array {
        $items = [];
        foreach ( $response['groups'] as $group ) {
            foreach ( $group['items'] as $item ) {
                $items[ $item['id'] ] = $item;
            }
        }

        return $items;
    }

    private function get(): \WP_REST_Response {
        return rest_do_request( new \WP_REST_Request( 'GET', self::ROUTE ) );
    }

    private function post( array $body ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'POST', self::ROUTE );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );

        return rest_do_request( $request );
    }
}
