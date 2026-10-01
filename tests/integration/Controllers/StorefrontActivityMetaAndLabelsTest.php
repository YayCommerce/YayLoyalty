<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Table;

/**
 * GET /me/activity shows each ledger row as the database holds it: the stored UTC time in the site's
 * timezone, the shopper-facing order number, and a store-attributed label that leaks no admin note.
 */
final class StorefrontActivityMetaAndLabelsTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private int $user_id = 0;

    /** @var array<string, mixed> */
    private array $saved_options = [];

    protected function setUp(): void {
        parent::setUp();

        rest_get_server();

        foreach ( [ 'timezone_string', 'gmt_offset', 'date_format', 'blogname' ] as $option ) {
            $this->saved_options[ $option ] = get_option( $option );
        }

        $this->require_woocommerce();
        $this->user_id = $this->create_customer();
        wp_set_current_user( $this->user_id );
    }

    protected function tearDown(): void {
        foreach ( $this->saved_options as $option => $value ) {
            update_option( $option, $value );
        }

        remove_all_filters( 'woocommerce_order_number' );

        $this->cleanup_orders();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function test_date_is_stored_utc_shown_in_site_timezone(): void {
        update_option( 'timezone_string', 'Asia/Saigon' );
        update_option( 'date_format', 'Y-m-d' );

        $this->ledger_service()->adjust( $this->user_id, 10, 'late-night fix', 1 );
        $this->set_latest_created_at( '2026-09-30 18:30:00' );

        $this->assertSame( '2026-10-01', $this->items()[0]['meta'], 'UTC 18:30 on 30 Sep is 01:30 on 1 Oct in UTC+7.' );
    }

    public function test_meta_uses_the_shopper_facing_order_number(): void {
        $order = $this->create_order( $this->user_id, [ 10 ] );

        add_filter(
            'woocommerce_order_number',
            static function () {
                return 'WEB-0042';
            }
        );

        $this->ledger_service()->earn( $this->earn_draft( $this->user_id, $order->get_id(), 10 ) );

        $this->assertStringEndsWith( '· order #WEB-0042', $this->items()[0]['meta'] );
    }

    public function test_deleted_order_falls_back_to_raw_id(): void {
        $this->ledger_service()->earn( $this->earn_draft( $this->user_id, 987654, 10 ) );

        $this->assertStringEndsWith( '· order #987654', $this->items()[0]['meta'] );
    }

    public function test_row_without_order_shows_date_only(): void {
        $this->ledger_service()->adjust( $this->user_id, 15, 'goodwill', 1 );

        $this->assertStringNotContainsString( 'order #', $this->items()[0]['meta'] );
        $this->assertStringNotContainsString( ' · ', $this->items()[0]['meta'] );
    }

    public function test_adjustment_is_attributed_to_the_store_never_the_note_or_admin(): void {
        update_option( 'blogname', 'Brandy &amp; Co' );

        $this->ledger_service()->adjust( $this->user_id, -20, 'Customer was rude on the phone', 1 );

        $item = $this->items()[0];

        $this->assertSame( 'Adjustment by Brandy & Co', $item['label'] );
        $this->assertSame( '−20', $item['delta'] );
        $this->assertStringNotContainsString( 'rude', wp_json_encode( $item ) );
    }

    public function test_balance_badge_matches_ledger_after_activity(): void {
        $this->ledger_service()->earn( $this->earn_draft( $this->user_id, 5001, 270 ) );
        $this->ledger_service()->adjust( $this->user_id, -120, 'correction', 1, false );

        $row = $this->customer_row( $this->user_id );

        $this->assertSame( 150, (int) $row['balance'] );
        $this->assertSame( array_sum( $this->ledger_points( $this->user_id ) ), (int) $row['balance'] );
        $this->assertSame( [ -120, 270 ], array_column( $this->items(), 'points' ) );
    }

    private function items(): array {
        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $this->assertSame( 200, $response->get_status() );

        return $response->get_data()['items'];
    }

    private function set_latest_created_at( string $utc ): void {
        global $wpdb;

        $table = esc_sql( Table::name( 'ledger' ) );
        $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET created_at = %s WHERE user_id = %d ORDER BY id DESC LIMIT 1", $utc, $this->user_id ) ); // phpcs:ignore WordPress.DB
    }
}
