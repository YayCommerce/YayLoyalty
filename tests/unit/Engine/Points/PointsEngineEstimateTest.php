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
 * Before an order exists the engine must never promise more than the order may pay: a campaign that might
 * catch this shopper lowers the figure, and only a campaign sure to apply ends the walk.
 */
final class PointsEngineEstimateTest extends EngineTestCase {

    /** @var object */
    private $evaluator;

    protected function setUp(): void {
        $this->evaluator = new class() {
            /** @var int */
            public $verdict_calls = 0;

            public function evaluate( array $conditions, Context $ctx ): bool {
                return true;
            }

            public function verdict( array $conditions, Context $ctx ): ?bool {
                ++$this->verdict_calls;

                $verdicts = [
                    'yes'     => true,
                    'no'      => false,
                    'unknown' => null,
                ];

                return $verdicts[ $conditions[0]['type'] ];
            }
        };

        $evaluator = $this->evaluator;
        add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, static fn() => $evaluator );
    }

    public function test_a_true_verdict_pays_its_own_rate(): void {
        $this->assertSame( 300, $this->estimate( [ $this->conditional( 'yes', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
    }

    public function test_a_false_verdict_is_skipped(): void {
        $this->assertSame( 100, $this->estimate( [ $this->conditional( 'no', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
    }

    public function test_an_unknown_campaign_paying_less_lowers_the_figure(): void {
        $this->assertSame( 50, $this->estimate( [ $this->conditional( 'unknown', 0.5, 2 ), $this->everyone( 1 ) ] )->get_points() );
    }

    public function test_an_unknown_campaign_paying_more_keeps_the_figure(): void {
        $this->assertSame( 100, $this->estimate( [ $this->conditional( 'unknown', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
    }

    public function test_an_unknown_campaign_paying_nothing_promises_nothing(): void {
        $result = $this->estimate( [ $this->conditional( 'unknown', 0, 2 ), $this->everyone( 1 ) ] );

        $this->assertFalse( $result->is_awarded() );
        $this->assertSame( Result::REASON_ZERO_POINTS, $result->get_reason() );
    }

    public function test_only_unknown_campaigns_promise_nothing(): void {
        $result = $this->estimate( [ $this->conditional( 'unknown', 3, 2 ) ] );

        $this->assertFalse( $result->is_awarded() );
        $this->assertSame( Result::REASON_NOT_ELIGIBLE, $result->get_reason() );
    }

    public function test_a_matches_subscriber_can_skip_a_true_verdict(): void {
        add_filter( 'yayloyalty_campaign_matches', static fn( $matches, Campaign $campaign ) => 2 === $campaign->get_id() ? false : $matches, 10, 2 );

        $this->assertSame( 100, $this->estimate( [ $this->conditional( 'yes', 3, 2 ), $this->everyone( 1 ) ] )->get_points() );
    }

    public function test_an_event_context_never_asks_for_a_verdict(): void {
        $engine = PointsEngine::build( $this->repository( [ $this->conditional( 'unknown', 3, 2 ), $this->everyone( 1 ) ] ) );

        // The stub's evaluate() matches every type, so the event pays the conditional campaign.
        $this->assertSame( 300, $engine->evaluate( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 100.0 ) ] ) )->get_points() );
        $this->assertSame( 0, $this->evaluator->verdict_calls );
    }

    /**
     * @param Campaign[] $campaigns
     */
    private function estimate( array $campaigns ): Result {
        $engine = PointsEngine::build( $this->repository( $campaigns ) );

        return $engine->evaluate( CampaignTrigger::PURCHASE, $this->context( [ $this->line( 100.0 ) ] )->as_estimate() );
    }

    private function conditional( string $type, float $rate, int $id ): Campaign {
        return new Campaign( $id, 'Conditional', 'conditional-' . $id, CampaignTrigger::PURCHASE, Status::ACTIVE, 10, array_merge( Campaign::default_config( CampaignTrigger::PURCHASE ), [ 'points_per_unit' => $rate ] ), [ [ 'type' => $type ] ] );
    }

    private function everyone( float $rate ): Campaign {
        return $this->purchase_campaign( [ 'points_per_unit' => $rate ], 0, 9, Status::ACTIVE, null, null, 'everyone' );
    }
}
