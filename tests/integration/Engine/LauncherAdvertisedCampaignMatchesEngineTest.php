<?php
namespace YayLoyalty\Tests\Integration\Engine;

use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Dates;

/**
 * The "Ways to earn" rate the launcher reads from the campaigns table is the rate the engine pays.
 *
 * Rows go through the real repository (priority DESC, id DESC, live window in UTC), so a mismatch
 * between what a shopper is promised and what the ledger later records shows up here.
 */
final class LauncherAdvertisedCampaignMatchesEngineTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private const AT = '2026-10-01 08:00:00';

    /** @var CampaignRepository */
    private $campaigns;

    private int $default_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->campaigns  = new CampaignRepository();
        $this->default_id = $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 1 ], null, [ 'slug' => 'default-purchase', 'is_builtin' => true ] );
    }

    protected function tearDown(): void {
        if ( function_exists( 'WC' ) ) {
            $this->cleanup_orders();
        }

        parent::tearDown();
    }

    public function test_campaign_starting_exactly_now_is_advertised(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ], null, [ 'priority' => 10, 'starts_at' => self::AT ] );

        $this->assertSame( 3.0, $this->advertised_purchase_rate() );
    }

    public function test_campaign_starting_later_falls_back_to_builtin(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ], null, [ 'priority' => 10, 'starts_at' => '2026-10-01 08:00:01' ] );

        $this->assertSame( 1.0, $this->advertised_purchase_rate() );
    }

    public function test_campaign_ending_exactly_now_is_not_advertised(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ], self::AT, [ 'priority' => 10 ] );

        $this->assertSame( 1.0, $this->advertised_purchase_rate() );
    }

    public function test_archived_and_draft_campaigns_are_not_advertised(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 9 ], null, [ 'priority' => 20, 'status' => Status::ARCHIVED ] );
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 7 ], null, [ 'priority' => 30, 'status' => Status::DRAFT ] );

        $this->assertSame( 1.0, $this->advertised_purchase_rate() );
    }

    public function test_higher_priority_conditioned_campaign_is_skipped(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 5 ], null, [ 'priority' => 20, 'conditions' => [ [ 'type' => 'user_role', 'operator' => 'in', 'value' => [ 'vip' ] ] ] ] );

        $this->assertSame( 1.0, $this->advertised_purchase_rate() );
    }

    public function test_priority_tie_is_won_by_the_campaign_the_engine_pays(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 2 ], null, [ 'priority' => 10 ] );
        $newer = $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 4 ], null, [ 'priority' => 10 ] );

        $this->require_woocommerce();
        $result = PointsEngine::build( $this->campaigns )
            ->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( wc_get_product( $this->create_product() ) ) );

        $this->assertSame( 4.0, $this->advertised_purchase_rate( Dates::now() ) );
        $this->assertSame( $newer, $result->get_campaign()->get_id(), 'The engine must pay the campaign the launcher advertises.' );
    }

    public function test_advertised_rate_times_price_equals_engine_points(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 3 ], null, [ 'priority' => 10 ] );

        $this->assertSame( 30, $this->engine_points_for_ten_dollar_product() );
        $this->assertSame( 3.0, $this->advertised_purchase_rate( Dates::now() ) );
    }

    /** Test case EARN-09 / D1: a no-condition multiplier outranking the built-in changes what is paid. */
    public function test_multiplier_campaign_advertised_rate_matches_paid_rate(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'mode' => 'multiplier', 'multiplier' => 3 ], null, [ 'priority' => 30 ] );

        $paid_per_unit = $this->engine_points_for_ten_dollar_product() / 10;

        $this->assertSame( 3.0, (float) $paid_per_unit );
        $this->assertSame( (float) $paid_per_unit, $this->advertised_purchase_rate( Dates::now() ) );
    }

    public function test_multiplier_skipped_when_default_paused_like_the_engine(): void {
        $this->set_default_status( Status::PAUSED );
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'mode' => 'multiplier', 'multiplier' => 3 ], null, [ 'priority' => 30 ] );
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 2 ], null, [ 'priority' => 10 ] );

        $this->assertSame( 20, $this->engine_points_for_ten_dollar_product() );
        $this->assertSame( 2.0, $this->advertised_purchase_rate( Dates::now() ) );
    }

    /** Test case EARN-11 / D5: without an evaluator (Lite) the engine pays a conditioned campaign to everyone. */
    public function test_conditioned_campaign_advertised_when_conditions_not_enforced(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 5 ], null, [ 'priority' => 20, 'conditions' => [ [ 'type' => 'user_role', 'operator' => 'in', 'value' => [ 'vip' ] ] ] ] );
        add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, '__return_null', PHP_INT_MAX );

        try {
            $this->assertSame( 50, $this->engine_points_for_ten_dollar_product() );
            $this->assertSame( 5.0, $this->advertised_purchase_rate( Dates::now() ) );
        } finally {
            remove_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, '__return_null', PHP_INT_MAX );
        }
    }

    public function test_min_subtotal_of_the_advertised_campaign_is_exposed(): void {
        $this->create_campaign( CampaignTrigger::PURCHASE, [ 'points_per_unit' => 2, 'min_subtotal' => 50 ], null, [ 'priority' => 10 ] );

        $live = $this->campaigns->live_for_trigger( CampaignTrigger::PURCHASE, self::AT );

        $this->assertSame( 50.0, LauncherEarnRules::advertise( $live, $this->campaigns->find_default_purchase() )['purchase_min'] );
    }

    public function test_flat_points_come_from_the_highest_priority_unconditioned_campaign(): void {
        $this->create_campaign( CampaignTrigger::SIGNUP, [ 'points' => 100 ], null, [ 'is_builtin' => true ] );
        $this->create_campaign( CampaignTrigger::SIGNUP, [ 'points' => 250 ], null, [ 'priority' => 5 ] );
        $this->create_campaign( CampaignTrigger::REVIEW, [ 'points' => 50 ], null, [ 'is_builtin' => true, 'status' => Status::PAUSED ] );

        $rules = $this->rules_at( self::AT );

        $this->assertSame( 250, $rules[ CampaignTrigger::SIGNUP ] );
        $this->assertNull( $rules[ CampaignTrigger::REVIEW ] );
    }

    private function advertised_purchase_rate( string $at = self::AT ): ?float {
        return $this->rules_at( $at )[ CampaignTrigger::PURCHASE ];
    }

    private function rules_at( string $at ): array {
        $live = [];

        foreach ( LauncherEarnRules::TRIGGERS as $trigger ) {
            $live = array_merge( $live, $this->campaigns->live_for_trigger( $trigger, $at ) );
        }

        return LauncherEarnRules::advertise( $live, $this->campaigns->find_default_purchase() )['earn'];
    }

    private function set_default_status( string $status ): void {
        $this->campaigns->update( $this->default_id, [ 'status' => $status ] );
    }

    private function engine_points_for_ten_dollar_product(): int {
        $this->require_woocommerce();

        return PointsEngine::build( $this->campaigns )
            ->evaluate( CampaignTrigger::PURCHASE, ContextFactory::from_product( wc_get_product( $this->create_product() ) ) )
            ->get_points();
    }
}
