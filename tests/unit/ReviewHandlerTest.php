<?php
namespace YayLoyalty\Tests\Unit;

use YayLoyalty\Engine\Points\Triggers\ReviewHandler;
use YayLoyalty\Enums\CampaignTrigger;

/** one_per_product is decided from a Context fact the caller read, never by the handler querying anything. */
final class ReviewHandlerTest extends EngineTestCase {

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: bool}>
     */
    public function one_per_product_cases(): array {
        return [
            'on, already earned'   => [ [ 'one_per_product' => true ], true, false ],
            'on, first review'     => [ [ 'one_per_product' => true ], false, true ],
            'off, already earned'  => [ [ 'one_per_product' => false ], true, true ],
            'off, first review'    => [ [ 'one_per_product' => false ], false, true ],
            'unset defaults to on' => [ [], true, false ],
            'unset, first review'  => [ [], false, true ],
        ];
    }

    /**
     * @dataProvider one_per_product_cases
     * @param array<string, mixed> $config
     */
    public function test_one_per_product_follows_config_and_the_already_earned_fact( array $config, bool $already_earned, bool $eligible ): void {
        $campaign = $this->flat_campaign(
            CampaignTrigger::REVIEW,
            $config + [
                'points'           => 50,
                'require_verified' => false,
            ]
        );
        $ctx      = $this->context( [], 0.0, [], 1.0, 0, 7, [ ReviewHandler::EXTRA_PRODUCT_ALREADY_EARNED => $already_earned ], 99 );

        $this->assertSame( $eligible, ( new ReviewHandler() )->is_eligible( $campaign, $ctx ) );
    }

    /** A config without require_verified follows the review campaign's default, which requires it. */
    public function test_unset_require_verified_defaults_to_required(): void {
        $campaign = $this->flat_campaign( CampaignTrigger::REVIEW, [ 'points' => 50 ] );

        $this->assertFalse( ( new ReviewHandler() )->is_eligible( $campaign, $this->context( [], 0.0, [], 1.0, 0, 7, [], 99 ) ) );
        $this->assertTrue(
            ( new ReviewHandler() )->is_eligible( $campaign, $this->context( [], 0.0, [], 1.0, 0, 7, [ ReviewHandler::EXTRA_VERIFIED_PURCHASE => true ], 99 ) )
        );
    }
}
