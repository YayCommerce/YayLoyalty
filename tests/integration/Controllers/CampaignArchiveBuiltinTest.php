<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * The server, not the archive dialog, refuses to archive a built-in; and
 * the campaign resource carries the lifetime figure the dialog decides from.
 * Ids are CampaignRestController::dummy_fixture() rows: 4 is the built-in
 * purchase campaign, 1 a regular one, 23 the ended one.
 */
final class CampaignArchiveBuiltinTest extends IntegrationTestCase {

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

        wp_set_current_user( (int) $admins[0] );
        rest_get_server(); // Fires rest_api_init, which is what registers the plugin's routes.
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_archiving_a_builtin_is_refused(): void {
        $this->assertSame( 403, $this->put_status( 4, 'archived' )->get_status() );
    }

    public function test_pausing_a_builtin_is_allowed(): void {
        $this->assertSame( 200, $this->put_status( 4, 'paused' )->get_status() );
    }

    public function test_archiving_a_regular_campaign_is_allowed(): void {
        $response = $this->put_status( 1, 'archived' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 412300, $response->get_data()['points_issued_lifetime'] );
    }

    /** An ended campaign's performance window is its whole run, so the two figures are one fact. */
    public function test_an_ended_campaign_lifetime_matches_its_performance_total(): void {
        $campaign    = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/campaigns/23' ) );
        $performance = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/campaigns/23/performance' ) );

        $this->assertSame( 200, $campaign->get_status() );
        $this->assertSame( 200, $performance->get_status() );
        $this->assertGreaterThan( 0, $campaign->get_data()['points_issued_lifetime'] );
        $this->assertSame( $performance->get_data()['totals']['points_issued'], $campaign->get_data()['points_issued_lifetime'] );
    }

    private function put_status( int $id, string $status ): \WP_REST_Response {
        $request = new \WP_REST_Request( 'PUT', sprintf( '/yayloyalty/v1/campaigns/%d', $id ) );
        $request->set_body_params( [ 'status' => $status ] );

        return rest_do_request( $request );
    }
}
