<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * The rewards route: built-in first by display order, archived rows still
 * returned (the SPA hides them, not the server), and the capability gate.
 */
final class RewardRouteTest extends IntegrationTestCase {

    private int $subscriber_id = 0;

    protected function setUp(): void {
        parent::setUp();

        rest_get_server(); // Fires rest_api_init, which is what registers the plugin's routes.
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );

        if ( $this->subscriber_id > 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $this->subscriber_id );
        }

        parent::tearDown();
    }

    public function test_the_list_is_ordered_by_sort_order_with_the_builtin_first(): void {
        $this->act_as_admin();

        $repository = new RewardRepository();
        $now        = gmdate( 'Y-m-d H:i:s' );

        $repository->insert( new Reward( null, 'Second', 'reward-route-second', RewardType::FIXED_COUPON, Status::ACTIVE, 2, 500, [], [], null, null, false, null, null, $now, $now ) );
        $repository->insert( new Reward( null, 'First', 'reward-route-first', RewardType::FIXED_COUPON, Status::ACTIVE, 1, 500, [], [], null, null, false, null, null, $now, $now ) );
        $repository->insert( new Reward( null, 'Builtin', 'reward-route-builtin', RewardType::FLEXIBLE_DISCOUNT, Status::ACTIVE, 0, null, [], [], null, null, true, null, null, $now, $now ) );

        $data = $this->get_rewards();

        $this->assertSame( [ 'Builtin', 'First', 'Second' ], array_column( $data['data'], 'name' ) );
    }

    public function test_an_archived_reward_is_still_returned(): void {
        $this->act_as_admin();

        $now = gmdate( 'Y-m-d H:i:s' );
        ( new RewardRepository() )->insert(
            new Reward( null, 'Retired', 'reward-route-archived', RewardType::FIXED_COUPON, Status::ARCHIVED, 3, 500, [], [], null, null, false, null, null, $now, $now )
        );

        $data = $this->get_rewards();

        $this->assertContains( 'Retired', array_column( $data['data'], 'name' ) );
    }

    public function test_a_caller_without_the_capability_is_refused(): void {
        // A leftover from a previous failed run is reused rather than colliding on the unique email.
        $existing            = get_user_by( 'login', 'reward-route-test-subscriber' );
        $this->subscriber_id = $existing ? (int) $existing->ID : (int) wp_insert_user(
            [
                'user_login'   => 'reward-route-test-subscriber',
                'user_email'   => 'reward-route-test-subscriber@example.com',
                'user_pass'    => wp_generate_password(),
                'display_name' => 'Reward Route Test Subscriber',
                'role'         => 'subscriber',
            ]
        );
        $this->assertGreaterThan( 0, $this->subscriber_id );

        wp_set_current_user( $this->subscriber_id );

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/rewards' ) );

        $this->assertSame( 403, $response->get_status() );
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

    /** @return array<string, mixed> */
    private function get_rewards(): array {
        $request = new \WP_REST_Request( 'GET', '/yayloyalty/v1/rewards' );
        $request->set_param( 'per_page', 100 );

        $response = rest_do_request( $request );

        $this->assertSame( 200, $response->get_status() );

        return $response->get_data();
    }
}
