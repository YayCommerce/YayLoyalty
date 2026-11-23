<?php
namespace YayLoyalty\Tests\Unit\Engine\Points;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\Multiplier;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Tests\Unit\EngineTestCase;

/**
 * The points multiplier: applied after the campaign's rounding and cap, in integer hundredths, and
 * ignored unless the answer is a Multiplier from 1.00 to 10.00.
 */
final class PointsEngineMultiplierTest extends EngineTestCase {

    public function test_multiplier_floors_after_the_campaign(): void {
        $this->multiply_by( new Multiplier( '1.25', 7 ) );
        $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 101.0 ) );

        $this->assertSame( 126, $result->get_points() );
        $this->assertSame( 126, $result->get_breakdown()->get_points() );
        $this->assertSame( 101, $result->get_breakdown()->get_campaign_points() );
        $this->assertSame( '1.25', $result->get_breakdown()->get_multiplier() );
        $this->assertSame( 7, $result->get_multiplier()->get_tier_id() );
    }

    public function test_campaign_cap_bounds_points_before_the_multiplier(): void {
        $this->multiply_by( new Multiplier( '1.50', 9 ) );
        $result = $this->engine( [ $this->purchase_campaign( [ 'max_points_per_order' => 100 ] ) ] )
            ->evaluate( CampaignTrigger::PURCHASE, $this->order( 150.0 ) );

        $this->assertSame( 150, $result->get_points() );
        $this->assertSame( 100, $result->get_breakdown()->get_campaign_points() );
    }

    public function test_multiplier_uses_integer_math(): void {
        $this->multiply_by( new Multiplier( '1.15', 7 ) );
        $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 100.0 ) );

        $this->assertSame( 115, $result->get_points() );
    }

    /**
     * @dataProvider malformed_answers
     *
     * @param mixed $answer What the filter returns.
     */
    public function test_malformed_answer_is_neutral( $answer ): void {
        $this->multiply_by( $answer );
        $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 101.0 ) );

        $this->assertSame( 101, $result->get_points() );
        $this->assertTrue( $result->get_multiplier()->is_neutral() );
        $this->assertSame( '1.00', $result->get_breakdown()->get_multiplier() );
    }

    /** @return array<string, array{mixed}> */
    public function malformed_answers(): array {
        return [
            'string'       => [ '1.25' ],
            'null'         => [ null ],
            'below 1.00'   => [ new Multiplier( '0.50', 7 ) ],
            'above 10.00'  => [ new Multiplier( '10.05', 7 ) ],
            'three places' => [ new Multiplier( '1.255', 7 ) ],
        ];
    }

    public function test_no_subscriber_leaves_the_result_as_today(): void {
        $result = $this->engine( [ $this->purchase_campaign() ] )->evaluate( CampaignTrigger::PURCHASE, $this->order( 101.0 ) );

        $this->assertSame( 101, $result->get_points() );
        $this->assertSame( 101, $result->get_breakdown()->get_points() );
        $this->assertSame( 101, $result->get_breakdown()->get_campaign_points() );
        $this->assertSame( '1.00', $result->get_breakdown()->get_multiplier() );
        $this->assertTrue( $result->get_multiplier()->is_neutral() );
        $this->assertNull( $result->get_multiplier()->get_tier_id() );
    }

    /**
     * @param mixed $answer What the filter returns.
     */
    private function multiply_by( $answer ): void {
        add_filter(
            PointsEngine::MULTIPLIER_FILTER,
            static function () use ( $answer ) {
                return $answer;
            },
            10,
            3
        );
    }

    private function engine( array $campaigns ): PointsEngine {
        return PointsEngine::build( $this->repository( $campaigns ) );
    }

    private function order( float $subtotal ): Context {
        return $this->context( [ $this->line( $subtotal ) ] );
    }
}
