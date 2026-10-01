<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * GET /yayloyalty/v1/me/activity returns the current user's latest ledger rows.
 *
 * Guest gets 401. Signed-in customer gets only their own rows, newest first, max 3.
 * Points are signed with U+2212 minus for negatives: "+270" / "−120".
 */
final class StorefrontActivityRouteTest extends IntegrationTestCase {

    private int $customer_user_id       = 0;
    private int $other_customer_user_id = 0;

    protected function setUp(): void {
        parent::setUp();

        // Register routes (via rest_api_init).
        rest_get_server();

        // Create customers.
        $this->customer_user_id = (int) wp_insert_user(
            [
                'user_login'   => 'activity-test-customer-1',
                'user_email'   => 'activity-test-1@example.com',
                'user_pass'    => wp_generate_password(),
                'display_name' => 'Activity Test Customer 1',
                'role'         => 'customer',
            ]
        );

        $this->other_customer_user_id = (int) wp_insert_user(
            [
                'user_login'   => 'activity-test-customer-2',
                'user_email'   => 'activity-test-2@example.com',
                'user_pass'    => wp_generate_password(),
                'display_name' => 'Activity Test Customer 2',
                'role'         => 'customer',
            ]
        );
    }

    protected function tearDown(): void {
        if ( $this->customer_user_id > 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $this->customer_user_id );
        }

        if ( $this->other_customer_user_id > 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $this->other_customer_user_id );
        }

        wp_set_current_user( 0 );
        parent::tearDown();
    }

    public function test_guest_gets_401_unauthorized(): void {
        wp_set_current_user( 0 );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $this->assertSame( 401, $response->get_status() );
        $this->assertSame( 'unauthorized', $response->get_data()['code'] );
    }

    public function test_signed_in_customer_gets_their_rows(): void {
        wp_set_current_user( $this->customer_user_id );

        // Insert some ledger rows.
        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 100, 1000 )
        );
        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 101, 500 )
        );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertArrayHasKey( 'items', $data );
        $this->assertCount( 2, $data['items'] );
    }

    public function test_rows_are_newest_first(): void {
        wp_set_current_user( $this->customer_user_id );

        // Insert three rows, oldest to newest.
        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 100, 100 )
        );
        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 101, 200 )
        );
        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 102, 300 )
        );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $data  = $response->get_data();
        $items = $data['items'];

        // Rows should be newest first (highest order id first).
        $this->assertSame( 300, $items[0]['points'] );
        $this->assertSame( 200, $items[1]['points'] );
        $this->assertSame( 100, $items[2]['points'] );
    }

    public function test_max_three_rows_returned(): void {
        wp_set_current_user( $this->customer_user_id );

        // Insert five rows.
        for ( $i = 1; $i <= 5; $i++ ) {
            $this->ledger_service()->earn(
                $this->earn_draft( $this->customer_user_id, 100 + $i, 100 * $i )
            );
        }

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $data = $response->get_data();
        $this->assertCount( 3, $data['items'], 'Only 3 rows should be returned' );
    }

    public function test_other_users_rows_never_appear(): void {
        wp_set_current_user( $this->customer_user_id );

        // Insert rows for both users.
        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 100, 1000 )
        );
        $this->ledger_service()->earn(
            $this->earn_draft( $this->other_customer_user_id, 101, 2000 )
        );
        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 102, 3000 )
        );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $data  = $response->get_data();
        $items = $data['items'];

        // Only rows for customer_user_id should appear.
        $this->assertCount( 2, $items );
        $this->assertSame( 3000, $items[0]['points'] );
        $this->assertSame( 1000, $items[1]['points'] );
    }

    public function test_positive_points_signed_with_plus(): void {
        wp_set_current_user( $this->customer_user_id );

        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 100, 270 )
        );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $data = $response->get_data();
        $item = $data['items'][0];

        $this->assertSame( 270, $item['points'] );
        // delta should be "+270" (with actual plus sign).
        $this->assertStringStartsWith( '+', $item['delta'] );
        $this->assertStringContainsString( '270', $item['delta'] );
    }

    public function test_negative_points_signed_with_minus(): void {
        wp_set_current_user( $this->customer_user_id );

        // Create a refund entry to get negative points (which is used when customers get refunds).
        $earn_draft = $this->earn_draft( $this->customer_user_id, 100, 500 );
        $this->ledger_service()->earn( $earn_draft );

        // Now create a refund entry by directly inserting into the ledger table.
        global $wpdb;
        $wpdb->insert(
            esc_sql( \YayLoyalty\Utils\Table::name( 'ledger' ) ),
            [
                'user_id'        => $this->customer_user_id,
                'points'         => -120,
                'balance_after'  => 380,
                'type'           => \YayLoyalty\Enums\LedgerType::REFUND_EARN,
                'source'         => \YayLoyalty\Enums\LedgerSource::ORDER,
                'reference_type' => \YayLoyalty\Enums\ReferenceType::ORDER,
                'reference_id'   => 100,
                'order_id'       => 100,
                'created_at'     => \YayLoyalty\Utils\Dates::now(),
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ]
        );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $data = $response->get_data();
        // We should have at least 2 items (earn + refund), get the refund one.
        $items       = $data['items'];
        $refund_item = array_values( array_filter( $items, static fn( $i ) => -120 === $i['points'] ) )[0] ?? null;

        $this->assertNotNull( $refund_item, 'Should find the refund item' );
        $this->assertSame( -120, $refund_item['points'] );
        // delta should contain "−" (U+2212 minus) not regular hyphen.
        $this->assertStringContainsString( '−', $refund_item['delta'], 'Should use U+2212 minus character' );
    }

    public function test_row_has_required_fields(): void {
        wp_set_current_user( $this->customer_user_id );

        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 100, 100 )
        );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $data = $response->get_data();
        $item = $data['items'][0];

        $this->assertArrayHasKey( 'label', $item );
        $this->assertArrayHasKey( 'meta', $item );
        $this->assertArrayHasKey( 'points', $item );
        $this->assertArrayHasKey( 'delta', $item );

        $this->assertIsString( $item['label'] );
        $this->assertIsString( $item['meta'] );
        $this->assertIsInt( $item['points'] );
        $this->assertIsString( $item['delta'] );
    }

    public function test_meta_contains_date_and_order_id(): void {
        wp_set_current_user( $this->customer_user_id );

        $this->ledger_service()->earn(
            $this->earn_draft( $this->customer_user_id, 1000, 100 )
        );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/me/activity' ) );

        $data = $response->get_data();
        $item = $data['items'][0];

        $this->assertNotEmpty( $item['meta'], 'meta should contain date and order info' );
        $this->assertStringContainsString( 'order #1000', $item['meta'] );
    }
}
