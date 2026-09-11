<?php
namespace YayLoyalty\Tests\Unit;

use YayLoyalty\Engine\Points\CapPolicy;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Result;
use YayLoyalty\Engine\Points\Triggers\Registry;
use YayLoyalty\Engine\Points\Triggers\ReviewHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;

/**
 * Plan §6 cases 22–28, 32, 34, 35: liveness, selection order, the three
 * `none` reasons, the daily cap hitting zero, and the review handler.
 */
final class PointsEngineTest extends EngineTestCase {

    /**
     * @param Campaign[] $campaigns
     */
    private function engine( array $campaigns ): PointsEngine {
        $repository = $this->repository( $campaigns );

        return new PointsEngine( Registry::with_lite_handlers( $repository ), $repository, new CapPolicy() );
    }

    private function purchase( array $campaigns, ?Context $ctx = null, string $at = self::AT ): Result {
        return $this->engine( $campaigns )->evaluate( CampaignTrigger::PURCHASE, $ctx ?? $this->context( [ $this->line( 100 ) ] ), $at );
    }

    /* --- Candidates --- */

    public function test_22_paused_campaign_is_not_a_candidate(): void {
        $result = $this->purchase( [ $this->purchase_campaign( [], 0, 1, Status::PAUSED ) ] );

        $this->assertFalse( $result->is_awarded() );
        $this->assertSame( Result::REASON_NO_CAMPAIGNS, $result->get_reason() );
    }

    public function test_23_at_exactly_starts_at_is_live(): void {
        $result = $this->purchase( [ $this->purchase_campaign( [], 0, 1, Status::ACTIVE, self::AT, null ) ] );

        $this->assertTrue( $result->is_awarded() );
    }

    public function test_24_at_exactly_ends_at_is_not_live(): void {
        $result = $this->purchase( [ $this->purchase_campaign( [], 0, 1, Status::ACTIVE, null, self::AT ) ] );

        $this->assertSame( Result::REASON_NO_CAMPAIGNS, $result->get_reason() );
    }

    public function test_25_highest_priority_wins(): void {
        $low  = $this->purchase_campaign( [ 'points_per_unit' => 1 ], 0, 1 );
        $high = $this->purchase_campaign( [ 'points_per_unit' => 3 ], 10, 2 );

        $result = $this->purchase( [ $low, $high ] );

        $this->assertSame( 2, $result->get_campaign()->get_id() );
        $this->assertSame( 300, $result->get_points() );
    }

    public function test_26_equal_priority_newest_id_wins(): void {
        $older = $this->purchase_campaign( [ 'points_per_unit' => 1 ], 5, 5 );
        $newer = $this->purchase_campaign( [ 'points_per_unit' => 2 ], 5, 9 );

        $this->assertSame( 9, $this->purchase( [ $older, $newer ] )->get_campaign()->get_id() );
    }

    public function test_27_no_campaigns_for_trigger(): void {
        $this->assertSame( Result::REASON_NO_CAMPAIGNS, $this->purchase( [] )->get_reason() );
    }

    public function test_28_all_candidates_ineligible(): void {
        $result = $this->purchase( [ $this->purchase_campaign( [ 'min_subtotal' => 500 ] ) ] );

        $this->assertSame( Result::REASON_NOT_ELIGIBLE, $result->get_reason() );
    }

    public function test_ineligible_boost_falls_through_to_default(): void {
        // "Trail Shoes boost, 3 pts/$, minimum $50" on a $30 order earns 1 pt/$ from the default, not zero.
        $default = $this->default_purchase_campaign( [ 'points_per_unit' => 1 ] );
        $boost   = $this->purchase_campaign(
            [
                'points_per_unit' => 3,
                'min_subtotal'    => 50,
            ],
            10,
            2
        );

        $result = $this->purchase( [ $default, $boost ], $this->context( [ $this->line( 30 ) ] ) );

        $this->assertSame( 1, $result->get_campaign()->get_id() );
        $this->assertSame( 30, $result->get_points() );
    }

    public function test_32_daily_cap_already_reached_is_zero_points(): void {
        $campaign = $this->purchase_campaign( [ 'daily_cap' => 200 ] );
        $result   = $this->purchase( [ $campaign ], $this->context( [ $this->line( 100 ) ], 0.0, [], 1.0, 200 ) );

        $this->assertFalse( $result->is_awarded() );
        $this->assertSame( Result::REASON_ZERO_POINTS, $result->get_reason() );
    }

    public function test_awarded_result_carries_the_ledger_reference(): void {
        $result = $this->purchase( [ $this->purchase_campaign() ] );

        $this->assertTrue( $result->is_awarded() );
        $this->assertSame( '', $result->get_reason() );
        $this->assertSame( ReferenceType::ORDER, $result->get_reference_type() );
        $this->assertSame( 555, $result->get_reference_id() );
        $this->assertSame( LedgerSource::ORDER, $result->get_source() );
        $this->assertSame( 100, $result->get_breakdown()->get_points() );
    }

    public function test_unknown_trigger_throws(): void {
        $this->expectException( \InvalidArgumentException::class );

        $this->engine( [] )->evaluate( 'birthday', $this->context( [] ), self::AT );
    }

    /* --- Filters --- */

    public function test_candidates_filter_can_replace_the_list(): void {
        $seen = null;

        add_filter(
            'yayloyalty_campaign_candidates',
            static function ( $campaigns, $trigger, $ctx ) use ( &$seen ) {
                $seen = [ count( $campaigns ), $trigger, get_class( $ctx ) ];

                return [];
            }
        );

        $result = $this->purchase( [ $this->purchase_campaign() ] );

        $this->assertSame( Result::REASON_NO_CAMPAIGNS, $result->get_reason() );
        $this->assertSame( [ 1, CampaignTrigger::PURCHASE, Context::class ], $seen );
    }

    public function test_matches_filter_can_veto_a_campaign(): void {
        $seen = null;

        add_filter(
            'yayloyalty_campaign_matches',
            static function ( $matches, $campaign, $ctx ) use ( &$seen ) {
                $seen = [ $matches, get_class( $campaign ), get_class( $ctx ) ];

                return false;
            }
        );

        $result = $this->purchase( [ $this->purchase_campaign() ] );

        $this->assertSame( Result::REASON_NOT_ELIGIBLE, $result->get_reason() );
        $this->assertSame( [ true, Campaign::class, Context::class ], $seen );
    }

    /* --- Flat handlers --- */

    public function test_signup_is_flat_and_references_the_user(): void {
        $campaign = $this->flat_campaign( CampaignTrigger::SIGNUP, [ 'points' => 100 ] );
        $result   = $this->engine( [ $campaign ] )->evaluate( CampaignTrigger::SIGNUP, $this->context( [], 0.0, [], 1.0, 0, 42 ), self::AT );

        $this->assertSame( 100, $result->get_points() );
        $this->assertSame( ReferenceType::USER, $result->get_reference_type() );
        $this->assertSame( 42, $result->get_reference_id() );
        $this->assertSame( LedgerSource::SIGNUP, $result->get_source() );
    }

    public function test_review_awards_and_references_the_comment(): void {
        $campaign = $this->flat_campaign(
            CampaignTrigger::REVIEW,
            [
                'points'           => 50,
                'require_verified' => true,
            ]
        );
        $ctx      = $this->context( [], 0.0, [], 1.0, 0, 7, [ ReviewHandler::EXTRA_VERIFIED_PURCHASE => true ], 901 );
        $result   = $this->engine( [ $campaign ] )->evaluate( CampaignTrigger::REVIEW, $ctx, self::AT );

        $this->assertSame( 50, $result->get_points() );
        $this->assertSame( ReferenceType::COMMENT, $result->get_reference_type() );
        $this->assertSame( 901, $result->get_reference_id() );
        $this->assertSame( LedgerSource::REVIEW, $result->get_source() );
    }

    public function test_34_review_require_verified_false_is_skipped(): void {
        $campaign = $this->flat_campaign(
            CampaignTrigger::REVIEW,
            [
                'points'           => 50,
                'require_verified' => true,
            ]
        );
        $ctx      = $this->context( [], 0.0, [], 1.0, 0, 7, [ ReviewHandler::EXTRA_VERIFIED_PURCHASE => false ], 901 );

        $this->assertSame( Result::REASON_NOT_ELIGIBLE, $this->engine( [ $campaign ] )->evaluate( CampaignTrigger::REVIEW, $ctx, self::AT )->get_reason() );
    }

    public function test_35_review_require_verified_absent_key_is_skipped(): void {
        $campaign = $this->flat_campaign(
            CampaignTrigger::REVIEW,
            [
                'points'           => 50,
                'require_verified' => true,
            ]
        );
        $ctx      = $this->context( [], 0.0, [], 1.0, 0, 7, [], 901 );

        $this->assertSame( Result::REASON_NOT_ELIGIBLE, $this->engine( [ $campaign ] )->evaluate( CampaignTrigger::REVIEW, $ctx, self::AT )->get_reason() );
    }
}
