<?php
namespace YayLoyalty\Tests\Unit\Engine\Points;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Tests\Unit\EngineTestCase;

/**
 * The bonuses the storefront may tease: conditional campaigns above the first campaign for everyone that pay more
 * than the base, each with the viewer's status.
 */
final class PointsEngineOffersTest extends EngineTestCase {

    public function test_bonuses_are_conditional_campaigns_above_the_base_paying_more(): void {
        $this->attach_explainer(
            [
                'user_role'        => [ false, 'no_customer' ],
                'category_in_cart' => [ false, 'needs_order' ],
            ]
        );
        $vip   = $this->conditional( 2, 2.0, 10, 'user_role' );
        $shoes = $this->conditional( 3, 3.0, 20, 'category_in_cart' );
        // Above the base and paying less: it lowers the base and is no bonus.
        $lower = $this->conditional( 4, 0.5, 30, 'user_role' );
        // Below the base: it never pays.
        $below = $this->conditional( 5, 9.0, -1, 'user_role' );

        $offers = $this->engine( [ $this->base(), $vip, $shoes, $lower, $below ] )->offers( CampaignTrigger::PURCHASE, $this->advert(), null );

        $this->assertSame( 0.5, $offers['base']['value'] );
        $this->assertSame( [ $shoes, $vip ], array_column( $offers['bonuses'], 'campaign' ) );
        $this->assertSame( [ 3.0, 2.0 ], array_column( $offers['bonuses'], 'value' ) );
        $this->assertSame( [ PointsEngine::OFFER_AT_CHECKOUT, PointsEngine::OFFER_LOCKED ], array_column( $offers['bonuses'], 'status' ) );
    }

    public function test_viewer_who_meets_every_condition_is_matched(): void {
        $this->attach_explainer( [ 'user_role' => [ true, 'matched' ] ] );

        $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertSame( PointsEngine::OFFER_MATCHED, $offers['bonuses'][0]['status'] );
    }

    public function test_failed_condition_beats_needs_order(): void {
        $this->attach_explainer(
            [
                'user_role'        => [ false, 'no_overlap' ],
                'category_in_cart' => [ false, 'needs_order' ],
            ]
        );
        $campaign = new Campaign(
            2,
            'Both',
            'both',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            10,
            [ 'points_per_unit' => 2 ],
            [ [ 'type' => 'user_role' ], [ 'type' => 'category_in_cart' ] ]
        );

        $offers = $this->engine( [ $campaign, $this->base() ] )->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertSame( PointsEngine::OFFER_LOCKED, $offers['bonuses'][0]['status'] );
    }

    public function test_matched_campaign_refused_by_the_matches_filter_is_locked(): void {
        $this->attach_explainer( [ 'user_role' => [ true, 'matched' ] ] );
        add_filter( 'yayloyalty_campaign_matches', static fn() => false );

        $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertSame( PointsEngine::OFFER_LOCKED, $offers['bonuses'][0]['status'] );
    }

    public function test_offers_returns_every_bonus_above_the_base(): void {
        $this->attach_explainer( [ 'user_role' => [ false, 'no_customer' ] ] );
        $campaigns = [ $this->base() ];

        for ( $id = 2; $id <= 6; $id++ ) {
            $campaigns[] = $this->conditional( $id, (float) $id, 10 + $id, 'user_role' );
        }

        $offers = $this->engine( $campaigns )->offers( CampaignTrigger::PURCHASE, $this->advert(), null );

        $this->assertCount( 5, $offers['bonuses'] );
    }

    public function test_without_an_evaluator_there_are_no_bonuses(): void {
        $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), null );

        $this->assertSame( [], $offers['bonuses'] );
        // Lite enforces no conditions, so the conditional campaign is for everyone.
        $this->assertSame( 2.0, $offers['base']['value'] );
    }

    public function test_evaluator_without_explain_gives_no_bonuses(): void {
        add_filter(
            PointsEngine::CONDITIONS_EVALUATOR_FILTER,
            static fn() => new class() {
                public function evaluate( array $conditions, Context $ctx ): bool {
                    return true;
                }
            }
        );

        $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), null );

        $this->assertSame( [], $offers['bonuses'] );
    }

    public function test_no_campaign_for_everyone_has_no_base_but_keeps_bonuses(): void {
        $this->attach_explainer( [ 'user_role' => [ true, 'matched' ] ] );

        $offers = $this->engine( [ $this->conditional( 2, 2.0, 10, 'user_role' ) ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertNull( $offers['base'] );
        $this->assertSame( PointsEngine::OFFER_MATCHED, $offers['bonuses'][0]['status'] );
    }

    public function test_member_row_is_a_lower_paying_campaign_they_meet(): void {
        // evaluate() would pay `lower` first, so the row must not promise VIP's 2.
        $this->attach_explainer(
            [
                'lower' => [ true, 'matched' ],
                'vip'   => [ true, 'matched' ],
            ]
        );
        $lower = $this->conditional( 4, 0.5, 30, 'lower' );

        $offers = $this->engine( [ $lower, $this->conditional( 2, 2.0, 10, 'vip' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertSame( $lower, $offers['row']['campaign'] );
        $this->assertSame( 0.5, $offers['row']['value'] );
    }

    public function test_member_row_is_capped_by_a_lower_paying_at_checkout_campaign(): void {
        // Checkout may pick `maybe` before VIP, so the most the member is sure of is 0.5.
        $this->attach_explainer(
            [
                'maybe' => [ false, 'needs_order' ],
                'vip'   => [ true, 'matched' ],
            ]
        );
        $maybe = $this->conditional( 4, 0.5, 30, 'maybe' );

        $offers = $this->engine( [ $maybe, $this->conditional( 2, 2.0, 10, 'vip' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertSame( $maybe, $offers['row']['campaign'] );
        $this->assertSame( 0.5, $offers['row']['value'] );
    }

    public function test_a_met_campaign_ends_the_walk(): void {
        // A bonus below the one the member meets would never pay them, so it is not offered.
        $this->attach_explainer(
            [
                'vip'   => [ true, 'matched' ],
                'shoes' => [ false, 'needs_order' ],
            ]
        );
        $vip = $this->conditional( 2, 2.0, 20, 'vip' );

        $offers = $this->engine( [ $vip, $this->conditional( 3, 3.0, 10, 'shoes' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertSame( [ $vip ], array_column( $offers['bonuses'], 'campaign' ) );
        $this->assertSame( 2.0, $offers['row']['value'] );
    }

    public function test_guest_row_is_the_base(): void {
        $this->attach_explainer( [ 'maybe' => [ false, 'needs_order' ] ] );

        $offers = $this->engine( [ $this->conditional( 4, 3.0, 30, 'maybe' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), null );

        $this->assertSame( $offers['base'], $offers['row'] );
        $this->assertSame( 1.0, $offers['row']['value'] );
    }

    public function test_member_with_nothing_sure_to_pay_has_no_row(): void {
        $this->attach_explainer( [ 'maybe' => [ false, 'needs_order' ] ] );

        $offers = $this->engine( [ $this->conditional( 4, 3.0, 30, 'maybe' ) ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertNull( $offers['row'] );
        $this->assertSame( PointsEngine::OFFER_AT_CHECKOUT, $offers['bonuses'][0]['status'] );
    }

    public function test_each_campaign_is_explained_once_and_never_evaluated(): void {
        $evaluator = $this->attach_explainer(
            [
                'vip'   => [ true, 'matched' ],
                'shoes' => [ false, 'needs_order' ],
            ]
        );

        $this->engine( [ $this->conditional( 3, 3.0, 20, 'shoes' ), $this->conditional( 2, 2.0, 10, 'vip' ), $this->base() ] )
            ->offers( CampaignTrigger::PURCHASE, $this->advert(), $this->context( [] ) );

        $this->assertSame( 2, $evaluator->explains );
        $this->assertSame( 0, $evaluator->evaluates );
    }

    public function test_guest_currency_campaign_in_another_currency_is_locked(): void {
        // The advert Context carries the store's currency, so `currency in [EUR]` fails now rather than waiting for the order.
        $evaluator = new ConditionsEvaluator();
        add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, static fn() => $evaluator );
        $eur = new Campaign(
            2,
            'Euro',
            'euro',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            10,
            [ 'points_per_unit' => 2 ],
            [
                [
                    'type'     => 'currency',
                    'operator' => 'in',
                    'value'    => [ 'EUR' ],
                ],
            ]
        );

        $offers = $this->engine( [ $eur, $this->base() ] )->offers( CampaignTrigger::PURCHASE, $this->advert(), null );

        $this->assertSame( PointsEngine::OFFER_LOCKED, $offers['bonuses'][0]['status'] );
    }

    /**
     * An evaluator whose explain() answers each condition from $by_type: [matched, reason].
     *
     * @param array<string, array{0: bool, 1: string}> $by_type
     */
    private function attach_explainer( array $by_type ): object {
        $evaluator = new class( $by_type ) {
            /** @var int */
            public $explains = 0;

            /** @var int */
            public $evaluates = 0;

            /** @var array<string, array{0: bool, 1: string}> */
            private $by_type;

            public function __construct( array $by_type ) {
                $this->by_type = $by_type;
            }

            public function explain( array $conditions, Context $ctx ): array {
                ++$this->explains;

                return $this->rows( $conditions );
            }

            public function evaluate( array $conditions, Context $ctx ): bool {
                ++$this->evaluates;

                return ! in_array( false, array_column( $this->rows( $conditions ), 'matched' ), true );
            }

            private function rows( array $conditions ): array {
                return array_map(
                    function ( array $condition ): array {
                        list( $matched, $reason ) = $this->by_type[ $condition['type'] ];

                        return [
                            'type'    => $condition['type'],
                            'matched' => $matched,
                            'reason'  => $reason,
                        ];
                    },
                    $conditions
                );
            }
        };

        add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, static fn() => $evaluator );

        return $evaluator;
    }

    private function engine( array $campaigns ): PointsEngine {
        return PointsEngine::build( $this->repository( $campaigns ) );
    }

    private function advert(): Context {
        return Context::advert( self::AT, 'USD', 1.0 );
    }

    private function base(): Campaign {
        return $this->purchase_campaign( [ 'points_per_unit' => 1 ], 0, 1, Status::ACTIVE, null, null, 'base' );
    }

    private function conditional( int $id, float $rate, int $priority, string $type ): Campaign {
        return new Campaign( $id, 'Conditional ' . $id, 'conditional-' . $id, CampaignTrigger::PURCHASE, Status::ACTIVE, $priority, [ 'points_per_unit' => $rate ], [ [ 'type' => $type ] ] );
    }
}
