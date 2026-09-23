<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * Which user ids each customer route accepts. A deleted member (customers row,
 * no WP user) must stay readable in the drawer but never writable; only a
 * real request through the REST server proves that, since the check calls
 * get_userdata().
 */
final class CustomerIdRouteParamTest extends IntegrationTestCase {

    /** Far above any real user id; each test asserts WP has no such user. */
    private const DELETED_MEMBER = 900000001;
    private const UNKNOWN_ID     = 900000002;

    /** @var int */
    private $admin_id;

    protected function setUp(): void {
        parent::setUp();

        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $this->assertNotEmpty( $admins, 'The site needs one administrator to act as the caller.' );

        $this->admin_id = (int) $admins[0];
        wp_set_current_user( $this->admin_id );
        rest_get_server(); // Fires rest_api_init, which is what registers the plugin's routes.

        $this->assertFalse( get_userdata( self::DELETED_MEMBER ) );
        $this->assertFalse( get_userdata( self::UNKNOWN_ID ) );

        $customers = new CustomerRepository();
        $customers->ensure_row( $this->admin_id );
        $customers->ensure_row( self::DELETED_MEMBER );
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function drawer_read_routes(): array {
        return [
            'item'        => [ '/yayloyalty/v1/customers/%d' ],
            'ledger'      => [ '/yayloyalty/v1/customers/%d/ledger' ],
            'activity'    => [ '/yayloyalty/v1/customers/%d/activity' ],
            'redemptions' => [ '/yayloyalty/v1/customers/%d/redemptions' ],
            'referrals'   => [ '/yayloyalty/v1/customers/%d/referrals' ],
        ];
    }

    /** @dataProvider drawer_read_routes */
    public function test_a_live_member_resolves( string $route ): void {
        $this->assertSame( 200, $this->get( $route, $this->admin_id )->get_status() );
    }

    /** @dataProvider drawer_read_routes */
    public function test_a_deleted_member_resolves( string $route ): void {
        $this->assertSame( 200, $this->get( $route, self::DELETED_MEMBER )->get_status() );
    }

    /** @dataProvider drawer_read_routes */
    public function test_an_unknown_id_is_refused( string $route ): void {
        $response = $this->get( $route, self::UNKNOWN_ID );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
    }

    public function test_adjust_refuses_a_deleted_member(): void {
        $request = new \WP_REST_Request( 'POST', sprintf( '/yayloyalty/v1/customers/%d/adjust', self::DELETED_MEMBER ) );
        $request->set_body_params(
            [
                'points' => 10,
                'note'   => 'integration probe',
            ]
        );

        $response = rest_do_request( $request );

        // `id` must be the only rejected arg, or a broken body would pass this without testing the id check.
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
        $this->assertSame( [ 'id' ], array_keys( $response->get_data()['data']['params'] ) );
        $this->assertSame( [], $this->ledger_rows( self::DELETED_MEMBER ) );
    }

    private function get( string $route, int $user_id ): \WP_REST_Response {
        return rest_do_request( new \WP_REST_Request( 'GET', sprintf( $route, $user_id ) ) );
    }
}
