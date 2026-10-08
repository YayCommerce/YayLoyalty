<?php
namespace YayLoyalty\Tests\Unit\Engine\Points;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Tests\Unit\EngineTestCase;

/**
 * The campaign the storefront advertises comes from the engine's own loop: it stops where the engine
 * stops, falls through where the engine falls through, and reaches the same filters.
 */
final class PointsEngineAdvertisedTest extends EngineTestCase {

    public function test_first_campaign_paying_zero_advertises_nothing(): void {
        $engine = $this->engine(
            [
                $this->purchase_campaign( [ 'points_per_unit' => 0 ], 10, 2, Status::ACTIVE, null, null, 'a' ),
                $this->purchase_campaign( [ 'points_per_unit' => 2 ], 0, 3, Status::ACTIVE, null, null, 'b' ),
            ]
        );

        $this->assertNull( $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() ) );
    }

    public function test_conditional_campaign_is_skipped_without_asking_the_evaluator(): void {
        $evaluator = new class() {
            /** @var int */
            public $calls = 0;

            public function evaluate( array $conditions, Context $ctx ): bool {
                ++$this->calls;

                return true;
            }
        };
        add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, static fn() => $evaluator );
        $everyone = $this->purchase_campaign( [ 'points_per_unit' => 3 ], 0, 3, Status::ACTIVE, null, null, 'everyone' );
        $engine   = $this->engine(
            [
                new Campaign( 2, 'Conditional', 'conditional', CampaignTrigger::PURCHASE, Status::ACTIVE, 10, [ 'points_per_unit' => 9 ], [ [ 'type' => 'country' ] ] ),
                $everyone,
            ]
        );

        $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() );

        $this->assertSame( $everyone, $advert['campaign'] );
        $this->assertSame( 3.0, $advert['value'] );
        $this->assertSame( 0, $evaluator->calls );
    }

    public function test_candidates_and_matches_filters_reach_the_advert(): void {
        $second = $this->purchase_campaign( [ 'points_per_unit' => 2 ], 0, 3, Status::ACTIVE, null, null, 'second' );
        $seen   = [];
        add_filter( 'yayloyalty_campaign_candidates', static fn( array $campaigns ) => array_slice( $campaigns, 1 ) );
        add_filter(
            'yayloyalty_campaign_matches',
            static function ( bool $matches, Campaign $campaign, Context $ctx ) use ( &$seen ) {
                $seen[] = $ctx->is_advert();

                return $matches;
            }
        );
        $engine = $this->engine(
            [
                $this->purchase_campaign( [ 'points_per_unit' => 5 ], 10, 2, Status::ACTIVE, null, null, 'first' ),
                $second,
            ]
        );

        $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() );

        $this->assertSame( $second, $advert['campaign'] );
        $this->assertSame( [ true ], $seen );
    }

    public function test_event_context_is_refused(): void {
        $this->expectException( \InvalidArgumentException::class );

        $this->engine( [] )->advertised( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 10.0 ) ] ) );
    }

    /**
     * @param Campaign[] $campaigns
     */
    private function engine( array $campaigns ): PointsEngine {
        return PointsEngine::build( $this->repository( $campaigns ) );
    }

    private function advert(): Context {
        return Context::advert( self::AT, 'USD', 1.0 );
    }
}
