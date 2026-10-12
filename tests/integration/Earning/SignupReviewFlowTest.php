<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** The signup and review campaigns, through user_register and the comment status hooks. */
final class SignupReviewFlowTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->pin_settings();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_self_registration_awards_signup_points_once(): void {
        $this->create_campaign( CampaignTrigger::SIGNUP );

        // A visitor: nobody is logged in while they register.
        wp_set_current_user( 0 );
        $user_id = $this->track_user(
            (int) wp_insert_user(
                [
                    'user_login' => 'yl_it_' . wp_generate_password( 8, false ),
                    'user_pass'  => wp_generate_password(),
                    'role'       => 'customer',
                ]
            )
        );

        do_action( 'user_register', $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- re-firing WordPress's own hook.

        $this->assertSame( [ 100 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 100, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_admin_created_user_earns_nothing(): void {
        $this->create_campaign( CampaignTrigger::SIGNUP );

        $user_id = $this->create_customer();

        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    public function test_verified_review_awards_and_unapprove_reverses(): void {
        $this->create_campaign( CampaignTrigger::REVIEW );
        $user_id    = $this->create_customer();
        $comment_id = $this->approve_new_review( $user_id, $this->create_product(), true );

        $this->assertSame( [ 50 ], $this->ledger_points( $user_id ) );

        wp_set_comment_status( $comment_id, 'hold' );

        $this->assertSame( [ 50, -50 ], $this->ledger_points( $user_id ) );
        $this->assertSame( 0, (int) $this->customer_row( $user_id )['balance'] );
    }

    public function test_unverified_review_earns_nothing(): void {
        $this->create_campaign( CampaignTrigger::REVIEW );
        $user_id = $this->create_customer();

        $this->approve_new_review( $user_id, $this->create_product() );

        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    public function test_second_review_same_product_earns_nothing(): void {
        $this->create_campaign( CampaignTrigger::REVIEW );
        $user_id    = $this->create_customer();
        $product_id = $this->create_product();

        $this->approve_new_review( $user_id, $product_id, true );
        $this->approve_new_review( $user_id, $product_id, true );

        $this->assertSame( [ 50 ], $this->ledger_points( $user_id ) );
    }
}
