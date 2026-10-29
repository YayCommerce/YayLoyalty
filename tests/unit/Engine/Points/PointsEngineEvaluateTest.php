<?php
namespace YayLoyalty\Tests\Unit\Engine\Points;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Tests\Unit\EngineTestCase;

/**
 * Which campaign pays an order and how much: an ineligible campaign falls through, one paying 0 stops.
 */
final class PointsEngineEvaluateTest extends EngineTestCase {

    public function test_no_live_campaign_is_no_campaigns(): void {
        $result = $this->engine( [] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 100.0 ) );

        $this->assertSame( Result::REASON_NO_CAMPAIGNS, $result->get_reason() );
    }

    public function test_campaign_under_its_minimum_falls_through_to_the_next(): void {
        $next   = $this->purchase_campaign( [ 'points_per_unit' => 2 ], 0, 3, Status::ACTIVE, null, null, 'next' );
        $result = $this->engine(
            [
                $this->purchase_campaign(
                    [
                        'points_per_unit' => 5,
                        'min_subtotal'    => 500,
                    ],
                    10,
                    2,
                    Status::ACTIVE,
                    null,
                    null,
                    'big-orders'
                ),
                $next,
            ]
        )->evaluate( CampaignTrigger::PURCHASE, $this->order( 100.0 ) );

        $this->assertSame( $next, $result->get_campaign() );
        $this->assertSame( 200, $result->get_points() );
    }

    public function test_first_campaign_paying_zero_stops_the_engine(): void {
        $result = $this->engine(
            [
                $this->purchase_campaign( [ 'points_per_unit' => 0 ], 10, 2, Status::ACTIVE, null, null, 'zero' ),
                $this->purchase_campaign( [ 'points_per_unit' => 2 ], 0, 3, Status::ACTIVE, null, null, 'next' ),
            ]
        )->evaluate( CampaignTrigger::PURCHASE, $this->order( 100.0 ) );

        $this->assertSame( Result::REASON_ZERO_POINTS, $result->get_reason() );
    }

    public function test_max_points_per_order_clamps(): void {
        $result = $this->engine( [ $this->purchase_campaign( [ 'max_points_per_order' => 50 ] ) ] )
            ->evaluate( CampaignTrigger::PURCHASE, $this->order( 100.0 ) );

        $this->assertSame( 50, $result->get_points() );
    }

    public function test_no_eligible_campaign_is_not_eligible(): void {
        $result = $this->engine( [ $this->purchase_campaign( [ 'min_subtotal' => 500 ] ) ] )
            ->evaluate( CampaignTrigger::PURCHASE, $this->order( 100.0 ) );

        $this->assertSame( Result::REASON_NOT_ELIGIBLE, $result->get_reason() );
    }

    public function test_reward_coupons_do_not_count_as_coupons_for_exclude_coupon_orders(): void {
        $campaign = $this->purchase_campaign(
            [
                'points_per_unit'       => 1,
                'exclude_coupon_orders' => true,
            ]
        );
        $ctx      = $this->context( [ $this->line( 100.0 ) ], 15.0, [ 'yayloyalty-ab12cd34', 'yayloyalty-ef56gh78' ] );

        $result = $this->engine( [ $campaign ] )->evaluate( CampaignTrigger::PURCHASE, $ctx );

        $this->assertTrue( $result->is_awarded() );
        $this->assertSame( [], $ctx->get_real_coupon_codes() );
    }

    public function test_a_merchant_coupon_still_counts_beside_a_reward_coupon(): void {
        $ctx = $this->context( [ $this->line( 100.0 ) ], 5.0, [ 'yayloyalty-ab12cd34', 'summer10' ] );

        $this->assertSame( [ 'summer10' ], $ctx->get_real_coupon_codes() );
    }

    public function test_advert_context_is_refused(): void {
        $this->expectException( \InvalidArgumentException::class );

        $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, Context::advert( self::AT, 'USD', 1.0 ) );
    }

    /**
     * @param Campaign[] $campaigns
     */
    private function engine( array $campaigns ): PointsEngine {
        return PointsEngine::build( $this->repository( $campaigns ) );
    }

    private function order( float $subtotal ): Context {
        return $this->context( [ $this->line( $subtotal ) ] );
    }
}
