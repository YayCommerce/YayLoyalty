<?php
namespace YayLoyalty\Tests\Unit;

use YayLoyalty\Engine\Points\BaseCalculator;
use YayLoyalty\Engine\Points\CapPolicy;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\Rounding;
use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;

/**
 * Plan §6 cases 14–21, 29, 33 (points, rounding, eligibility) and 30–31 (caps).
 */
final class PurchaseHandlerTest extends EngineTestCase {

    private function handler( array $repository_rows = [] ): PurchaseHandler {
        return new PurchaseHandler( $this->repository( $repository_rows ), new BaseCalculator() );
    }

    private function points( Campaign $campaign, Context $ctx, array $repository_rows = [] ): int {
        return $this->handler( $repository_rows )->compute_points( $campaign, $ctx )->get_points();
    }

    /* --- Points and rounding --- */

    public function test_14_rate_one(): void {
        $this->assertSame( 100, $this->points( $this->purchase_campaign(), $this->context( [ $this->line( 100 ) ] ) ) );
    }

    public function test_15_round_down(): void {
        $campaign = $this->purchase_campaign(
            [
                'points_per_unit' => 0.5,
                'rounding'        => Rounding::DOWN,
            ]
        );

        $this->assertSame( 49, $this->points( $campaign, $this->context( [ $this->line( 99 ) ] ) ) );
    }

    public function test_16_round_nearest(): void {
        $campaign = $this->purchase_campaign(
            [
                'points_per_unit' => 0.5,
                'rounding'        => Rounding::NEAREST,
            ]
        );

        $this->assertSame( 50, $this->points( $campaign, $this->context( [ $this->line( 99 ) ] ) ) );
    }

    public function test_17_round_up(): void {
        $campaign = $this->purchase_campaign(
            [
                'points_per_unit' => 0.5,
                'rounding'        => Rounding::UP,
            ]
        );

        $this->assertSame( 50, $this->points( $campaign, $this->context( [ $this->line( 99 ) ] ) ) );
    }

    public function test_18_multiplier_uses_default_rate(): void {
        $default    = $this->default_purchase_campaign( [ 'points_per_unit' => 1 ] );
        $multiplier = $this->purchase_campaign(
            [
                'mode'       => 'multiplier',
                'multiplier' => 2,
            ],
            10,
            2
        );
        $ctx        = $this->context( [ $this->line( 100 ) ] );

        $this->assertTrue( $this->handler( [ $default ] )->is_eligible( $multiplier, $ctx ) );
        $this->assertSame( 200, $this->points( $multiplier, $ctx, [ $default ] ) );
    }

    public function test_breakdown_records_base_rate_raw_and_rounding(): void {
        $campaign  = $this->purchase_campaign(
            [
                'points_per_unit' => 0.5,
                'rounding'        => Rounding::UP,
            ]
        );
        $breakdown = $this->handler()->compute_points( $campaign, $this->context( [ $this->line( 99 ) ] ) );

        $this->assertSame( 99.0, $breakdown->get_base_amount() );
        $this->assertSame( 0.5, $breakdown->get_rate() );
        $this->assertSame( 49.5, $breakdown->get_raw_points() );
        $this->assertSame( Rounding::UP, $breakdown->get_rounding() );
    }

    public function test_points_for_order_filter_fires_with_documented_signature(): void {
        $seen = null;

        add_filter(
            'yayloyalty_points_for_order',
            static function ( $points, $campaign, $ctx, $base, $rate ) use ( &$seen ) {
                $seen = [ $points, get_class( $campaign ), get_class( $ctx ), $base, $rate ];

                return $points + 5;
            }
        );

        $this->assertSame( 105, $this->points( $this->purchase_campaign(), $this->context( [ $this->line( 100 ) ] ) ) );
        $this->assertSame( [ 100, Campaign::class, Context::class, 100.0, 1.0 ], $seen );
    }

    /* --- Eligibility --- */

    public function test_19_min_subtotal_gate(): void {
        $campaign = $this->purchase_campaign( [ 'min_subtotal' => 50 ] );

        $this->assertFalse( $this->handler()->is_eligible( $campaign, $this->context( [ $this->line( 30 ) ] ) ) );
        $this->assertTrue( $this->handler()->is_eligible( $campaign, $this->context( [ $this->line( 50 ) ] ) ) );
    }

    public function test_20_exclude_coupon_orders_with_real_coupon(): void {
        $campaign = $this->purchase_campaign( [ 'exclude_coupon_orders' => true ] );

        $this->assertFalse( $this->handler()->is_eligible( $campaign, $this->context( [ $this->line( 100 ) ], 0.0, [ 'SAVE10' ] ) ) );
    }

    public function test_21_exclude_coupon_orders_ignores_our_redeem_coupon(): void {
        $campaign = $this->purchase_campaign( [ 'exclude_coupon_orders' => true ] );
        $ctx      = $this->context( [ $this->line( 100 ) ], 10.0, [ Context::REDEEM_COUPON_CODE ] );

        $this->assertTrue( $this->handler()->is_eligible( $campaign, $ctx ) );
    }

    public function test_29_multiplier_with_paused_default_is_ineligible(): void {
        $default    = $this->default_purchase_campaign( [], Status::PAUSED );
        $multiplier = $this->purchase_campaign(
            [
                'mode'       => 'multiplier',
                'multiplier' => 2,
            ],
            10,
            2
        );

        $this->assertFalse( $this->handler( [ $default ] )->is_eligible( $multiplier, $this->context( [ $this->line( 100 ) ] ) ) );
    }

    public function test_33_multiplier_with_no_default_row_is_ineligible_not_fatal(): void {
        $multiplier = $this->purchase_campaign(
            [
                'mode'       => 'multiplier',
                'multiplier' => 2,
            ],
            10,
            2
        );

        $this->assertFalse( $this->handler( [] )->is_eligible( $multiplier, $this->context( [ $this->line( 100 ) ] ) ) );
    }

    /* --- Caps --- */

    public function test_30_per_order_cap(): void {
        $campaign = $this->purchase_campaign( [ 'max_points_per_order' => 100 ] );

        $this->assertSame( 100, ( new CapPolicy() )->apply( 150, $campaign, $this->context( [] ) ) );
    }

    public function test_31_daily_cap_leaves_the_remainder(): void {
        $campaign = $this->purchase_campaign( [ 'daily_cap' => 200 ] );

        $this->assertSame( 20, ( new CapPolicy() )->apply( 50, $campaign, $this->context( [], 0.0, [], 1.0, 180 ) ) );
    }

    public function test_caps_of_zero_mean_unlimited(): void {
        $campaign = $this->purchase_campaign(
            [
                'max_points_per_order' => 0,
                'daily_cap'            => 0,
            ]
        );

        $this->assertSame( 999999, ( new CapPolicy() )->apply( 999999, $campaign, $this->context( [], 0.0, [], 1.0, 500 ) ) );
    }
}
