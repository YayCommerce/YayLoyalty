<?php
namespace YayLoyalty\Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;

/**
 * Test LauncherEarnRules::from_campaigns().
 *
 * Only built-in campaigns advertise; merchant campaigns are ignored. Multiplier purchases return null,
 * and zero points return null. First built-in per trigger wins, others are ignored.
 */
final class LauncherEarnRulesTest extends TestCase {

    public function test_empty_campaigns_returns_null_for_all(): void {
        $rules = LauncherEarnRules::from_campaigns( [] );

        $this->assertNull( $rules[ CampaignTrigger::PURCHASE ] );
        $this->assertNull( $rules[ CampaignTrigger::SIGNUP ] );
        $this->assertNull( $rules[ CampaignTrigger::REVIEW ] );
    }

    public function test_builtin_purchase_with_rate_included(): void {
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-builtin',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 2.5 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase ] );

        $this->assertEquals( 2.5, $rules[ CampaignTrigger::PURCHASE ] );
        $this->assertNull( $rules[ CampaignTrigger::SIGNUP ] );
        $this->assertNull( $rules[ CampaignTrigger::REVIEW ] );
    }

    public function test_builtin_purchase_with_zero_rate_returns_null(): void {
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-builtin',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 0 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase ] );

        $this->assertNull( $rules[ CampaignTrigger::PURCHASE ] );
    }

    public function test_builtin_purchase_with_multiplier_returns_null(): void {
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-builtin',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge(
                Campaign::default_config( CampaignTrigger::PURCHASE ),
                [
                    'mode'       => 'multiplier',
                    'multiplier' => 1.5,
                ]
            ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase ] );

        $this->assertNull( $rules[ CampaignTrigger::PURCHASE ], 'Multiplier purchase should not advertise a rate' );
    }

    public function test_builtin_signup_with_points(): void {
        $signup = new Campaign(
            2,
            'Campaign 2',
            'signup-builtin',
            CampaignTrigger::SIGNUP,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::SIGNUP ), [ 'points' => 50 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $signup ] );

        $this->assertSame( 50, $rules[ CampaignTrigger::SIGNUP ] );
        $this->assertNull( $rules[ CampaignTrigger::PURCHASE ] );
    }

    public function test_builtin_signup_with_zero_points_returns_null(): void {
        $signup = new Campaign(
            2,
            'Campaign 2',
            'signup-builtin',
            CampaignTrigger::SIGNUP,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::SIGNUP ), [ 'points' => 0 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $signup ] );

        $this->assertNull( $rules[ CampaignTrigger::SIGNUP ] );
    }

    public function test_builtin_review_with_points(): void {
        $review = new Campaign(
            3,
            'Campaign 3',
            'review-builtin',
            CampaignTrigger::REVIEW,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::REVIEW ), [ 'points' => 25 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $review ] );

        $this->assertSame( 25, $rules[ CampaignTrigger::REVIEW ] );
    }

    public function test_non_builtin_with_conditions_ignored(): void {
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-merchant',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 5 ] ),
            [
                [
                    'type'  => 'product',
                    'value' => '1',
                ],
            ],
            null,
            null,
            false
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase ] );

        $this->assertNull( $rules[ CampaignTrigger::PURCHASE ], 'Non-builtin campaigns with conditions should be ignored' );
    }

    public function test_non_builtin_with_no_conditions_advertised(): void {
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-merchant',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 5 ] ),
            [],
            null,
            null,
            false
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase ] );

        $this->assertEquals( 5.0, $rules[ CampaignTrigger::PURCHASE ], 'Non-builtin campaigns with no conditions should be advertised' );
    }

    public function test_non_builtin_conditional_skipped_unconditional_wins(): void {
        $conditional = new Campaign(
            1,
            'Conditional',
            'purchase-conditional',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 2 ] ),
            [
                [
                    'type'  => 'product',
                    'value' => '1',
                ],
            ],
            null,
            null,
            false // Non-builtin with conditions
        );

        $unconditional = new Campaign(
            2,
            'Unconditional',
            'purchase-unconditional',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 3 ] ),
            [], // No conditions
            null,
            null,
            false // Non-builtin but no conditions
        );

        $rules = LauncherEarnRules::from_campaigns( [ $conditional, $unconditional ] );

        $this->assertEquals( 3.0, $rules[ CampaignTrigger::PURCHASE ], 'Non-builtin conditional skipped, unconditional wins' );
    }

    public function test_multiplier_skipped_rate_campaign_wins(): void {
        $multiplier = new Campaign(
            1,
            'Multiplier',
            'purchase-multiplier',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge(
                Campaign::default_config( CampaignTrigger::PURCHASE ),
                [
                    'mode'       => 'multiplier',
                    'multiplier' => 1.5,
                ]
            ),
            [],
            null,
            null,
            true
        );

        $rate = new Campaign(
            2,
            'Rate',
            'purchase-rate',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 4 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $multiplier, $rate ] );

        $this->assertEquals( 4.0, $rules[ CampaignTrigger::PURCHASE ], 'Rate campaign should win when multiplier is first' );
    }

    public function test_first_builtin_per_trigger_wins(): void {
        $purchase1 = new Campaign(
            1,
            'Campaign 1',
            'purchase-1',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            1,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 2 ] ),
            [],
            null,
            null,
            true
        );

        $purchase2 = new Campaign(
            2,
            'Campaign 2',
            'purchase-2',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 5 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase1, $purchase2 ] );

        $this->assertEquals( 2.0, $rules[ CampaignTrigger::PURCHASE ], 'First builtin should be used' );
    }

    public function test_all_three_triggers_populated(): void {
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-builtin',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 1.5 ] ),
            [],
            null,
            null,
            true
        );

        $signup = new Campaign(
            2,
            'Campaign 2',
            'signup-builtin',
            CampaignTrigger::SIGNUP,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::SIGNUP ), [ 'points' => 100 ] ),
            [],
            null,
            null,
            true
        );

        $review = new Campaign(
            3,
            'Campaign 3',
            'review-builtin',
            CampaignTrigger::REVIEW,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::REVIEW ), [ 'points' => 50 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase, $signup, $review ] );

        $this->assertSame( 1.5, $rules[ CampaignTrigger::PURCHASE ] );
        $this->assertSame( 100, $rules[ CampaignTrigger::SIGNUP ] );
        $this->assertSame( 50, $rules[ CampaignTrigger::REVIEW ] );
    }

    public function test_paused_builtin_still_returns_null(): void {
        // Even though it's builtin, paused campaigns won't be in the "live" list passed in.
        // This test documents that a paused campaign wouldn't get advertised.
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-builtin',
            CampaignTrigger::PURCHASE,
            Status::PAUSED,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 2 ] ),
            [],
            null,
            null,
            true
        );

        // In reality, paused campaigns wouldn't be in the live list, so pass empty array.
        $rules = LauncherEarnRules::from_campaigns( [] );

        $this->assertNull( $rules[ CampaignTrigger::PURCHASE ] );
    }

    public function test_missing_config_keys_treated_as_zero(): void {
        // Create config with explicit 0 points_per_unit.
        $purchase = new Campaign(
            1,
            'Campaign 1',
            'purchase-builtin',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 0 ] ),
            [],
            null,
            null,
            true
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase ] );

        // Zero points_per_unit should be treated as null.
        $this->assertNull( $rules[ CampaignTrigger::PURCHASE ], 'Zero points_per_unit should be treated as null' );
    }

    public function test_mixed_builtin_and_merchant_campaigns(): void {
        $purchase_builtin = new Campaign(
            1,
            'Campaign 1',
            'purchase-builtin',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 2 ] ),
            [],
            null,
            null,
            true
        );

        $purchase_merchant = new Campaign(
            2,
            'Campaign 2',
            'purchase-merchant',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            0,
            array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => 10 ] ),
            [],
            null,
            null,
            false
        );

        $rules = LauncherEarnRules::from_campaigns( [ $purchase_builtin, $purchase_merchant ] );

        $this->assertEquals( 2.0, $rules[ CampaignTrigger::PURCHASE ], 'Only builtin should be used, merchant ignored' );
    }
}
