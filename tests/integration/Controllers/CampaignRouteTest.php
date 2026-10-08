<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/** How the campaign write routes set priority: never from the body, and on top of the group on restore. */
final class CampaignRouteTest extends IntegrationTestCase {

    protected function setUp(): void {
        parent::setUp();

        rest_get_server(); // Fires rest_api_init, which is what registers the plugin's routes.
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_create_ignores_a_priority_in_the_body(): void {
        $this->act_as_admin();
        $this->insert_campaign( 'Top now', CampaignTrigger::PURCHASE, Status::ACTIVE, 4 );

        $response = $this->send(
            'POST',
            '/yayloyalty/v1/campaigns',
            [
                'name'     => 'New',
                'trigger'  => CampaignTrigger::PURCHASE,
                'priority' => 99,
                'config'   => [ 'points_per_unit' => 1 ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 5, $response->get_data()['priority'] );
    }

    public function test_update_ignores_a_priority_in_the_body(): void {
        $this->act_as_admin();
        $id = $this->insert_campaign( 'Kept', CampaignTrigger::PURCHASE, Status::ACTIVE, 2 );

        $response = $this->send(
            'PUT',
            "/yayloyalty/v1/campaigns/{$id}",
            [
                'name'     => 'Renamed',
                'priority' => 99,
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'Renamed', $response->get_data()['name'] );
        $this->assertSame( 2, $this->stored_priority( $id ) );
    }

    public function test_restoring_an_archived_campaign_puts_it_on_top_of_its_group(): void {
        $this->act_as_admin();
        $restored = $this->insert_campaign( 'Restored', CampaignTrigger::PURCHASE, Status::ARCHIVED, 1 );
        $this->insert_campaign( 'Tied before', CampaignTrigger::PURCHASE, Status::ACTIVE, 1 );
        $this->insert_campaign( 'Group top', CampaignTrigger::PURCHASE, Status::ACTIVE, 3 );
        $this->insert_campaign( 'Other trigger', CampaignTrigger::SIGNUP, Status::ACTIVE, 10 );

        $response = $this->send( 'PUT', "/yayloyalty/v1/campaigns/{$restored}", [ 'status' => Status::ACTIVE ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 4, $response->get_data()['priority'] );
        $this->assertSame( 4, $this->stored_priority( $restored ) );
    }

    public function test_a_status_change_outside_archived_keeps_the_priority(): void {
        $this->act_as_admin();
        $paused = $this->insert_campaign( 'Paused', CampaignTrigger::PURCHASE, Status::PAUSED, 1 );
        $this->insert_campaign( 'Group top', CampaignTrigger::PURCHASE, Status::ACTIVE, 3 );

        $response = $this->send( 'PUT', "/yayloyalty/v1/campaigns/{$paused}", [ 'status' => Status::ACTIVE ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $this->stored_priority( $paused ) );
    }

    private function insert_campaign( string $name, string $trigger, string $status, int $priority ): int {
        $now = gmdate( 'Y-m-d H:i:s' );

        return ( new CampaignRepository() )->insert(
            new Campaign( null, $name, sanitize_title( $name ), $trigger, $status, $priority, Campaign::default_config( $trigger ), [], null, null, false, null, $now, $now )
        );
    }

    private function stored_priority( int $id ): int {
        $repository = new CampaignRepository();
        $repository->flush_cache();

        $campaign = $repository->find_campaign( $id );
        $this->assertNotNull( $campaign );

        return $campaign->get_priority();
    }

    /** @param array<string, mixed> $body */
    private function send( string $method, string $route, array $body = [] ): \WP_REST_Response {
        $request = new \WP_REST_Request( $method, $route );

        if ( [] !== $body ) {
            $request->set_header( 'Content-Type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }

        return rest_do_request( $request );
    }

    private function act_as_admin(): void {
        $admins = get_users(
            [
                'role'   => 'administrator',
                'number' => 1,
                'fields' => 'ID',
            ]
        );
        $this->assertNotEmpty( $admins, 'The site needs one administrator to act as the caller.' );

        wp_set_current_user( (int) $admins[0] );
    }
}
