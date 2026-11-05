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

    public function test_lower_paying_conditional_campaign_sets_the_rate(): void {
        $this->attach_evaluator();
        $conditional = $this->conditional_purchase( 0.5 );
        $engine      = $this->engine( [ $conditional, $this->purchase_campaign( [ 'points_per_unit' => 1 ], 0, 3, Status::ACTIVE, null, null, 'everyone' ) ] );

        $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() );

        $this->assertSame( $conditional, $advert['campaign'] );
        $this->assertSame( 0.5, $advert['value'] );
    }

    public function test_higher_paying_conditional_campaign_keeps_the_rate_for_everyone(): void {
        $this->attach_evaluator();
        $everyone = $this->purchase_campaign( [ 'points_per_unit' => 1 ], 0, 3, Status::ACTIVE, null, null, 'everyone' );
        $engine   = $this->engine( [ $this->conditional_purchase( 3 ), $everyone ] );

        $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->advert() );

        $this->assertSame( $everyone, $advert['campaign'] );
        $this->assertSame( 1.0, $advert['value'] );
    }

    public function test_only_conditional_campaigns_advertise_nothing(): void {
        $this->attach_evaluator();

        $this->assertNull( $this->engine( [ $this->conditional_purchase( 3 ) ] )->advertised( CampaignTrigger::PURCHASE, $this->advert() ) );
    }

    public function test_gated_review_campaign_paying_zero_advertises_nothing(): void {
        $engine = $this->engine(
            [
                $this->review_campaign( 2, 10, 0, true ),
                $this->review_campaign( 3, 0, 30, false ),
            ]
        );

        $this->assertNull( $engine->advertised( CampaignTrigger::REVIEW, $this->advert() ) );
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

    public function test_member_who_qualifies_is_advertised_the_conditional_rate(): void {
        $this->attach_verdicts();
        $conditional = $this->member_conditional( 'yes', 3 );
        $engine      = $this->engine( [ $conditional, $this->everyone( 1 ) ] );

        $advert = $engine->advertised( CampaignTrigger::PURCHASE, $this->member_advert() );

        $this->assertSame( $conditional, $advert['campaign'] );
        $this->assertSame( 3.0, $advert['value'] );
    }

    public function test_member_who_does_not_qualify_is_advertised_the_rate_below(): void {
        $this->attach_verdicts();
        $engine = $this->engine( [ $this->member_conditional( 'no', 0.5 ), $this->everyone( 1 ) ] );

        $this->assertSame( 1.0, $engine->advertised( CampaignTrigger::PURCHASE, $this->member_advert() )['value'] );
    }

    public function test_unknown_campaign_only_lowers_a_members_rate(): void {
        $this->attach_verdicts();

        $lower  = $this->engine( [ $this->member_conditional( 'unknown', 0.5 ), $this->everyone( 1 ) ] );
        $higher = $this->engine( [ $this->member_conditional( 'unknown', 3 ), $this->everyone( 1 ) ] );

        $this->assertSame( 0.5, $lower->advertised( CampaignTrigger::PURCHASE, $this->member_advert() )['value'] );
        $this->assertSame( 1.0, $higher->advertised( CampaignTrigger::PURCHASE, $this->member_advert() )['value'] );
    }

    public function test_only_unknown_campaigns_advertise_nothing_to_a_member(): void {
        $this->attach_verdicts();
        $engine = $this->engine( [ $this->member_conditional( 'unknown', 3 ) ] );

        $this->assertNull( $engine->advertised( CampaignTrigger::PURCHASE, $this->member_advert() ) );
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

    /** Any conditions evaluator, so a campaign with conditions stops applying to everyone. */
    private function attach_evaluator(): void {
        add_filter(
            PointsEngine::CONDITIONS_EVALUATOR_FILTER,
            static fn() => new class() {
                public function evaluate( array $conditions, Context $ctx ): bool {
                    return true;
                }
            }
        );
    }

    /** An evaluator answering by the condition's type: `yes`, `no` or `unknown`. */
    private function attach_verdicts(): void {
        add_filter(
            PointsEngine::CONDITIONS_EVALUATOR_FILTER,
            static fn() => new class() {
                public function evaluate( array $conditions, Context $ctx ): bool {
                    return true;
                }

                public function verdict( array $conditions, Context $ctx ): ?bool {
                    $verdicts = [
                        'yes'     => true,
                        'no'      => false,
                        'unknown' => null,
                    ];

                    return $verdicts[ $conditions[0]['type'] ];
                }
            }
        );
    }

    private function member_advert(): Context {
        return Context::advert( self::AT, 'USD', 1.0, 7, [ 'customer' ] );
    }

    private function member_conditional( string $type, float $rate ): Campaign {
        return new Campaign( 2, 'Conditional', 'conditional', CampaignTrigger::PURCHASE, Status::ACTIVE, 10, [ 'points_per_unit' => $rate ], [ [ 'type' => $type ] ] );
    }

    private function everyone( float $rate ): Campaign {
        return $this->purchase_campaign( [ 'points_per_unit' => $rate ], 0, 3, Status::ACTIVE, null, null, 'everyone' );
    }

    private function conditional_purchase( float $rate ): Campaign {
        return new Campaign( 2, 'Conditional', 'conditional', CampaignTrigger::PURCHASE, Status::ACTIVE, 10, [ 'points_per_unit' => $rate ], [ [ 'type' => 'country' ] ] );
    }

    private function review_campaign( int $id, int $priority, int $points, bool $require_verified ): Campaign {
        $config = [
            'points'           => $points,
            'require_verified' => $require_verified,
            'one_per_product'  => true,
        ];

        return new Campaign( $id, 'Review ' . $id, 'review-' . $id, CampaignTrigger::REVIEW, Status::ACTIVE, $priority, $config );
    }
}
