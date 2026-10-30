<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Database\Schema\RedemptionsTable;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

/**
 * The rewards routes: the list (built-in first by display order, archived
 * rows still returned), create and partial update with their validate-first
 * guards, delete and its redemption guard, and the capability gate on every
 * method.
 */
final class RewardRouteTest extends IntegrationTestCase {

    private int $subscriber_id = 0;

    protected function setUp(): void {
        parent::setUp();

        rest_get_server(); // Fires rest_api_init, which is what registers the plugin's routes.
    }

    protected function tearDown(): void {
        wp_set_current_user( 0 );
        $this->restore_redemptions_table();

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
        $this->act_as_subscriber();

        $response = rest_do_request( new \WP_REST_Request( 'GET', '/yayloyalty/v1/rewards' ) );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_create_stores_a_catalog_reward_after_every_existing_row(): void {
        $this->act_as_admin();
        $this->insert_reward( 'Existing', 'reward-route-existing', RewardType::FIXED_COUPON, 5, 500 );

        $response = $this->send( 'POST', '/yayloyalty/v1/rewards', $this->fixed_coupon_body() );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( '$5 off', $data['name'] );
        $this->assertSame( '5-off', $data['slug'] );
        $this->assertSame( Status::ACTIVE, $data['status'] );
        $this->assertSame( 6, $data['sort_order'] );
        $this->assertSame( 500, $data['cost_points'] );
        $this->assertSame( get_current_user_id(), $data['created_by'] );
        $this->assertSame( 'user_role', $data['conditions'][0]['type'] );
    }

    public function test_create_numbers_a_colliding_slug_and_honours_a_draft_status(): void {
        $this->act_as_admin();
        $this->send( 'POST', '/yayloyalty/v1/rewards', $this->fixed_coupon_body() );

        $response = $this->send( 'POST', '/yayloyalty/v1/rewards', [ 'status' => Status::DRAFT ] + $this->fixed_coupon_body() );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( '5-off-2', $response->get_data()['slug'] );
        $this->assertSame( Status::DRAFT, $response->get_data()['status'] );
    }

    public function test_create_only_starts_a_reward_active_or_as_a_draft(): void {
        $this->act_as_admin();

        foreach ( [ Status::ARCHIVED, Status::PAUSED ] as $status ) {
            $this->assertSame( 400, $this->send( 'POST', '/yayloyalty/v1/rewards', [ 'status' => $status ] + $this->fixed_coupon_body() )->get_status(), $status );
        }

        $this->assertSame( 0, $this->reward_count() );
    }

    public function test_writes_ignore_sort_order_and_an_update_ignores_type(): void {
        $this->act_as_admin();
        $this->insert_reward( 'Existing', 'reward-route-existing', RewardType::FIXED_COUPON, 4, 500 );

        $created = $this->send( 'POST', '/yayloyalty/v1/rewards', [ 'sort_order' => 99 ] + $this->fixed_coupon_body() )->get_data();
        $this->assertSame( 5, $created['sort_order'] );

        $response = $this->send(
            'PUT',
            '/yayloyalty/v1/rewards/' . $created['id'],
            [
                'sort_order' => 0,
                'type'       => 'not-a-type',
                'name'       => 'Renamed',
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 5, $response->get_data()['sort_order'] );
        $this->assertSame( RewardType::FIXED_COUPON, $response->get_data()['type'] );
    }

    public function test_create_refuses_a_flexible_discount(): void {
        $this->act_as_admin();

        $response = $this->send(
            'POST',
            '/yayloyalty/v1/rewards',
            [
                'name'   => 'Second flexible discount',
                'type'   => RewardType::FLEXIBLE_DISCOUNT,
                'config' => Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ),
            ]
        );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 0, $this->reward_count() );
    }

    public function test_create_refuses_invalid_fields_without_writing(): void {
        $this->act_as_admin();

        $bodies = [
            'no name'        => [ 'name' => '  ' ] + $this->fixed_coupon_body(),
            'zero cost'      => [ 'cost_points' => 0 ] + $this->fixed_coupon_body(),
            'bad config'     => [ 'config' => [ 'amount' => 0 ] + $this->fixed_coupon_body()['config'] ] + $this->fixed_coupon_body(),
            'unknown key'    => [ 'config' => [ 'percent' => 5 ] + $this->fixed_coupon_body()['config'] ] + $this->fixed_coupon_body(),
            'bad conditions' => [
                'conditions' => [
                    [
                        'type'     => 'lifetime_points',
                        'operator' => 'in',
                        'value'    => 1,
                    ],
                ],
            ] + $this->fixed_coupon_body(),
            'ends before'    => [
                'starts_at' => '2026-12-01 00:00:00',
                'ends_at'   => '2026-11-01 00:00:00',
            ] + $this->fixed_coupon_body(),
            'malformed date' => [ 'starts_at' => 'next week' ] + $this->fixed_coupon_body(),
        ];

        foreach ( $bodies as $label => $body ) {
            $this->assertSame( 400, $this->send( 'POST', '/yayloyalty/v1/rewards', $body )->get_status(), $label );
        }

        $this->assertSame( 0, $this->reward_count() );
    }

    public function test_a_free_product_reward_must_point_at_a_real_product(): void {
        $this->act_as_admin();

        $product = new \WC_Product_Simple();
        $product->set_name( 'Reward Route Tote' );
        $product->set_regular_price( '24' );
        $product_id = (int) $product->save();

        $body = [
            'name'        => 'Free tote',
            'type'        => RewardType::FREE_PRODUCT,
            'cost_points' => 3000,
            'config'      => [
                'product_id'   => 999999999,
                'variation_id' => null,
                'qty'          => 1,
            ],
        ];

        $this->assertSame( 400, $this->send( 'POST', '/yayloyalty/v1/rewards', $body )->get_status() );

        $body['config']['product_id'] = $product_id;
        $response                     = $this->send( 'POST', '/yayloyalty/v1/rewards', $body );

        wp_delete_post( $product_id, true );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $product_id, $response->get_data()['config']['product_id'] );
    }

    public function test_a_free_product_reward_refuses_drafts_trashed_products_and_variations(): void {
        $this->act_as_admin();

        $draft = new \WC_Product_Simple();
        $draft->set_name( 'Reward Route Draft' );
        $draft->set_status( 'draft' );
        $draft_id = (int) $draft->save();

        $trashed = new \WC_Product_Simple();
        $trashed->set_name( 'Reward Route Trashed' );
        $trashed_id = (int) $trashed->save();
        wp_trash_post( $trashed_id );

        $parent = new \WC_Product_Variable();
        $parent->set_name( 'Reward Route Variable' );
        $parent_id = (int) $parent->save();

        $variation = new \WC_Product_Variation();
        $variation->set_parent_id( $parent_id );
        $variation->set_regular_price( '10' );
        $variation_id = (int) $variation->save();

        $statuses = [];

        foreach ( [ $draft_id, $trashed_id, $variation_id ] as $product_id ) {
            $statuses[] = $this->send(
                'POST',
                '/yayloyalty/v1/rewards',
                [
                    'name'        => 'Free thing',
                    'type'        => RewardType::FREE_PRODUCT,
                    'cost_points' => 3000,
                    'config'      => [
                        'product_id' => $product_id,
                        'qty'        => 1,
                    ],
                ]
            )->get_status();
        }

        foreach ( [ $variation_id, $parent_id, $draft_id, $trashed_id ] as $product_id ) {
            wp_delete_post( $product_id, true );
        }

        $this->assertSame( [ 400, 400, 400 ], $statuses );
        $this->assertSame( 0, $this->reward_count() );
    }

    public function test_without_a_redemptions_table_every_catalog_reward_is_deletable_with_a_zero_summary(): void {
        global $wpdb;

        $this->drop_redemptions_table();
        $this->act_as_admin();
        $id      = $this->insert_reward( 'Catalog', 'reward-route-catalog', RewardType::FIXED_COUPON, 1, 500 );
        $builtin = $this->insert_reward( 'Points for discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, 0, null, true );

        $detail = $this->send( 'GET', '/yayloyalty/v1/rewards/' . $id )->get_data();
        $list   = array_column( $this->get_rewards()['data'], null, 'id' );

        $this->assertSame( '', $wpdb->last_error );
        $this->assertTrue( $detail['is_deletable'] );
        $this->assertSame( RedemptionRepository::empty_summary(), $detail['redemption_summary'] );
        $this->assertTrue( $list[ $id ]['is_deletable'] );
        $this->assertFalse( $list[ $builtin ]['is_deletable'] );
    }

    public function test_delete_removes_a_never_claimed_reward(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Unclaimed', 'reward-route-unclaimed', RewardType::FIXED_COUPON, 1, 500 );

        $response = $this->send( 'DELETE', '/yayloyalty/v1/rewards/' . $id );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [
                'deleted' => true,
                'id'      => $id,
            ],
            $response->get_data()
        );
        $this->assertNull( ( new RewardRepository() )->find_reward( $id ) );
    }

    public function test_delete_refuses_the_builtin_and_an_unknown_id(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Points for discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, 0, null, true );

        $this->assertSame( 403, $this->send( 'DELETE', '/yayloyalty/v1/rewards/' . $id )->get_status() );
        $this->assertNotNull( ( new RewardRepository() )->find_reward( $id ) );
        $this->assertSame( 404, $this->send( 'DELETE', '/yayloyalty/v1/rewards/999999' )->get_status() );
    }

    public function test_delete_refuses_a_caller_without_the_capability(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Guarded', 'reward-route-guarded', RewardType::FIXED_COUPON, 1, 500 );
        $this->act_as_subscriber();

        $this->assertSame( 403, $this->send( 'DELETE', '/yayloyalty/v1/rewards/' . $id )->get_status() );
        $this->assertNotNull( ( new RewardRepository() )->find_reward( $id ) );
    }

    public function test_a_claimed_reward_is_not_deletable_and_delete_answers_409(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Claimed', 'reward-route-claimed', RewardType::FIXED_COUPON, 1, 500 );
        // Refunded, so nothing spent remains: any claim row at all blocks the delete.
        $this->insert_redemption( $id, 7, 500, Redemption::STATUS_REFUNDED );

        $this->assertFalse( $this->send( 'GET', '/yayloyalty/v1/rewards/' . $id )->get_data()['is_deletable'] );
        $this->assertSame( 409, $this->send( 'DELETE', '/yayloyalty/v1/rewards/' . $id )->get_status() );
        $this->assertNotNull( ( new RewardRepository() )->find_reward( $id ) );
    }

    public function test_the_summary_counts_claims_in_every_status_and_the_first_claim_date(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Counted', 'reward-route-counted', RewardType::FIXED_COUPON, 1, 500 );

        $this->insert_redemption( $id, 7, 500, Redemption::STATUS_APPLIED, '2026-01-02 00:00:00' );
        $this->insert_redemption( $id, 8, 500, Redemption::STATUS_REFUNDED );
        $this->insert_redemption( $id, 9, 500, Redemption::STATUS_CANCELLED );

        $this->assertSame(
            [
                'claimed'          => 3,
                'first_claimed_at' => '2026-01-02 00:00:00',
            ],
            $this->send( 'GET', '/yayloyalty/v1/rewards/' . $id )->get_data()['redemption_summary']
        );
    }

    public function test_get_item_returns_one_reward_and_404s_an_unknown_id(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'One', 'reward-route-one', RewardType::FIXED_COUPON, 1, 500 );

        $this->assertSame( 'One', $this->send( 'GET', '/yayloyalty/v1/rewards/' . $id )->get_data()['name'] );
        $this->assertSame( 404, $this->send( 'GET', '/yayloyalty/v1/rewards/999999' )->get_status() );
        $this->assertSame( 404, $this->send( 'PUT', '/yayloyalty/v1/rewards/999999', [ 'name' => 'x' ] )->get_status() );
    }

    public function test_update_applies_only_the_sent_fields_and_keeps_type_and_slug(): void {
        $this->act_as_admin();
        $id = (int) $this->send( 'POST', '/yayloyalty/v1/rewards', $this->fixed_coupon_body() )->get_data()['id'];

        $response = $this->send(
            'PUT',
            '/yayloyalty/v1/rewards/' . $id,
            [
                'name'        => '$6 off',
                'cost_points' => 600,
                'slug'        => 'hijacked',
                'conditions'  => [],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $stored = ( new RewardRepository() )->find_reward( $id );
        $this->assertSame( '$6 off', $stored->get_name() );
        $this->assertSame( 600, $stored->get_cost_points() );
        $this->assertSame( '5-off', $stored->get_slug() );
        $this->assertSame( RewardType::FIXED_COUPON, $stored->get_type() );
        $this->assertSame( 5, $stored->get_config()['amount'] );
        $this->assertSame( [], $stored->get_conditions() );
    }

    public function test_status_moves_set_and_clear_archived_at(): void {
        $this->act_as_admin();
        $id         = (int) $this->send( 'POST', '/yayloyalty/v1/rewards', [ 'status' => Status::DRAFT ] + $this->fixed_coupon_body() )->get_data()['id'];
        $repository = new RewardRepository();

        $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'status' => Status::ARCHIVED ] );
        $this->assertNotNull( $repository->find_reward( $id )->get_archived_at() );

        $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'status' => Status::ACTIVE ] );
        $restored = $repository->find_reward( $id );
        $this->assertSame( Status::ACTIVE, $restored->get_status() );
        $this->assertNull( $restored->get_archived_at() );
    }

    public function test_restoring_an_archived_reward_as_paused_clears_archived_at(): void {
        $this->act_as_admin();
        $id         = $this->insert_reward( 'Retired', 'reward-route-restore', RewardType::FIXED_COUPON, 1, 500 );
        $repository = new RewardRepository();

        $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'status' => Status::ARCHIVED ] );
        $response = $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'status' => Status::PAUSED ] );

        $this->assertSame( 200, $response->get_status() );
        $restored = $repository->find_reward( $id );
        $this->assertSame( Status::PAUSED, $restored->get_status() );
        $this->assertNull( $restored->get_archived_at() );
    }

    public function test_an_archived_never_claimed_reward_can_still_be_deleted(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Retired', 'reward-route-archived-delete', RewardType::FIXED_COUPON, 1, 500 );
        $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'status' => Status::ARCHIVED ] );

        $this->assertTrue( $this->send( 'GET', '/yayloyalty/v1/rewards/' . $id )->get_data()['is_deletable'] );
        $this->assertSame( 200, $this->send( 'DELETE', '/yayloyalty/v1/rewards/' . $id )->get_status() );
        $this->assertNull( ( new RewardRepository() )->find_reward( $id ) );
    }

    public function test_the_builtin_cannot_be_archived_but_can_be_paused_and_reconfigured(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Points for discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, 0, null, true );

        $this->assertSame( 403, $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'status' => Status::ARCHIVED ] )->get_status() );

        $config              = Reward::default_config( RewardType::FLEXIBLE_DISCOUNT );
        $config['increment'] = 50;
        $response            = $this->send(
            'PUT',
            '/yayloyalty/v1/rewards/' . $id,
            [
                'status' => Status::PAUSED,
                'config' => $config,
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( Status::PAUSED, $response->get_data()['status'] );
        $this->assertSame( 50, $response->get_data()['config']['increment'] );
        $this->assertSame( 400, $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'cost_points' => 100 ] )->get_status() );
    }

    public function test_a_status_only_update_is_not_blocked_by_other_stored_fields(): void {
        $this->act_as_admin();
        // No config at all: a fixed_coupon missing its required keys, as a row written before validation existed.
        $id = $this->insert_reward( 'Legacy', 'reward-route-legacy', RewardType::FIXED_COUPON, 1, 500 );

        $this->assertSame( 200, $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'status' => Status::PAUSED ] )->get_status() );
    }

    public function test_write_routes_refuse_a_caller_without_the_capability(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Guarded', 'reward-route-guarded', RewardType::FIXED_COUPON, 1, 500 );
        $this->act_as_subscriber();

        $this->assertSame( 403, $this->send( 'GET', '/yayloyalty/v1/rewards/' . $id )->get_status() );
        $this->assertSame( 403, $this->send( 'POST', '/yayloyalty/v1/rewards', $this->fixed_coupon_body() )->get_status() );
        $this->assertSame( 403, $this->send( 'PUT', '/yayloyalty/v1/rewards/' . $id, [ 'name' => 'x' ] )->get_status() );
    }

    public function test_reorder_saves_each_position_as_sort_order(): void {
        $this->act_as_admin();
        $first  = $this->insert_reward( 'First', 'reward-route-a', RewardType::FIXED_COUPON, 1, 500 );
        $second = $this->insert_reward( 'Second', 'reward-route-b', RewardType::FIXED_COUPON, 2, 500 );
        $third  = $this->insert_reward( 'Third', 'reward-route-c', RewardType::FIXED_COUPON, 3, 500 );

        $response = $this->send( 'POST', '/yayloyalty/v1/rewards/reorder', [ 'ids' => [ $third, $first, $second ] ] );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'Third', 'First', 'Second' ], array_column( $this->get_rewards()['data'], 'name' ) );
    }

    public function test_reorder_refuses_duplicate_or_unknown_ids_without_writing(): void {
        $this->act_as_admin();
        $this->insert_reward( 'First', 'reward-route-a', RewardType::FIXED_COUPON, 1, 500 );
        $second = $this->insert_reward( 'Second', 'reward-route-b', RewardType::FIXED_COUPON, 2, 500 );

        $this->assertSame( 400, $this->send( 'POST', '/yayloyalty/v1/rewards/reorder', [ 'ids' => [ $second, $second ] ] )->get_status() );
        $this->assertSame( 400, $this->send( 'POST', '/yayloyalty/v1/rewards/reorder', [ 'ids' => [ $second, 999999 ] ] )->get_status() );
        $this->assertSame( 400, $this->send( 'POST', '/yayloyalty/v1/rewards/reorder', [ 'ids' => [] ] )->get_status() );
        $this->assertSame( [ 'First', 'Second' ], array_column( $this->get_rewards()['data'], 'name' ) );
    }

    public function test_reorder_puts_unlisted_rows_after_the_listed_ones_without_ties(): void {
        $this->act_as_admin();
        $first    = $this->insert_reward( 'First', 'reward-route-a', RewardType::FIXED_COUPON, 1, 500 );
        $archived = $this->insert_reward( 'Archived', 'reward-route-x', RewardType::FIXED_COUPON, 0, 500 );
        $second   = $this->insert_reward( 'Second', 'reward-route-b', RewardType::FIXED_COUPON, 2, 500 );

        $this->send( 'POST', '/yayloyalty/v1/rewards/reorder', [ 'ids' => [ $second, $first ] ] );

        $repository = new RewardRepository();
        $this->assertSame( 0, $repository->find_reward( $second )->get_sort_order() );
        $this->assertSame( 1, $repository->find_reward( $first )->get_sort_order() );
        $this->assertSame( 2, $repository->find_reward( $archived )->get_sort_order() );
    }

    public function test_reorder_refuses_a_caller_without_the_capability(): void {
        $this->act_as_admin();
        $id = $this->insert_reward( 'Guarded', 'reward-route-guarded', RewardType::FIXED_COUPON, 1, 500 );
        $this->act_as_subscriber();

        $this->assertSame( 403, $this->send( 'POST', '/yayloyalty/v1/rewards/reorder', [ 'ids' => [ $id ] ] )->get_status() );
    }

    private function act_as_subscriber(): void {
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
    }

    /** @return array<string, mixed> */
    private function fixed_coupon_body(): array {
        return [
            'name'        => '$5 off',
            'type'        => RewardType::FIXED_COUPON,
            'cost_points' => 500,
            'config'      => [
                'currency'           => get_woocommerce_currency(),
                'amount'             => 5,
                'min_order'          => 25,
                'exclude_sale_items' => false,
            ],
            'conditions'  => [
                [
                    'type'     => 'user_role',
                    'operator' => 'in',
                    'value'    => [ 'customer' ],
                ],
            ],
        ];
    }

    private function insert_reward( string $name, string $slug, string $type, int $sort_order, ?int $cost_points, bool $is_builtin = false ): int {
        $now = gmdate( 'Y-m-d H:i:s' );

        return ( new RewardRepository() )->insert(
            new Reward( null, $name, $slug, $type, Status::ACTIVE, $sort_order, $cost_points, RewardType::FLEXIBLE_DISCOUNT === $type ? Reward::default_config( $type ) : [], [], null, null, $is_builtin, null, null, $now, $now )
        );
    }

    private function drop_redemptions_table(): void {
        global $wpdb;

        $table = ( new RedemptionRepository() )->table();

        // The prefix check is the only thing standing between this DROP and real data.
        if ( 0 === strpos( $table, YAYLOYALTY_TEST_PREFIX ) ) {
            $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
    }

    private function insert_redemption( int $reward_id, int $user_id, int $points, string $status, string $created_at = '2026-03-01 00:00:00' ): void {
        ( new RedemptionRepository() )->insert(
            new Redemption( null, $user_id, $reward_id, null, $points, '0', 'USD', '0', '0', null, $status, 0, $created_at, $created_at )
        );
    }

    /** Puts back the table a test dropped, so the next test's truncate finds it. */
    private function restore_redemptions_table(): void {
        global $wpdb;

        $table = ( new RedemptionRepository() )->table();

        if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( ( new RedemptionsTable() )->definition() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own CREATE TABLE statement.
        }
    }

    private function reward_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( ( new RewardRepository() )->table() ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
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
