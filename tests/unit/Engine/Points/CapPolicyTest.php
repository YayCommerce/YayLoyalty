<?php
namespace YayLoyalty\Tests\Unit\Engine\Points;

use YayLoyalty\Engine\Points\CapPolicy;
use YayLoyalty\Tests\Unit\EngineTestCase;

/** A campaign's per-order and daily caps: null is no cap, and a stored 0 from before reads as null. */
final class CapPolicyTest extends EngineTestCase {

    public function test_null_caps_leave_points_unchanged(): void {
        $campaign = $this->purchase_campaign(
            [
                'max_points_per_order' => null,
                'daily_cap'            => null,
            ]
        );

        $this->assertSame( 500, ( new CapPolicy() )->apply( 500, $campaign, $this->context( [], 0.0, [], 1.0, 400 ) ) );
    }

    public function test_a_stored_zero_is_no_cap(): void {
        $campaign = $this->purchase_campaign(
            [
                'max_points_per_order' => 0,
                'daily_cap'            => 0,
            ]
        );

        $this->assertNull( $campaign->get_config()['daily_cap'] );
        $this->assertSame( 500, ( new CapPolicy() )->apply( 500, $campaign, $this->context( [] ) ) );
    }

    public function test_per_order_cap_clamps(): void {
        $campaign = $this->purchase_campaign( [ 'max_points_per_order' => 120 ] );

        $this->assertSame( 120, ( new CapPolicy() )->apply( 500, $campaign, $this->context( [] ) ) );
    }

    public function test_daily_cap_clamps_to_what_is_left_today(): void {
        $campaign = $this->purchase_campaign( [ 'daily_cap' => 300 ] );

        $this->assertSame( 50, ( new CapPolicy() )->apply( 500, $campaign, $this->context( [], 0.0, [], 1.0, 250 ) ) );
        $this->assertSame( 0, ( new CapPolicy() )->apply( 500, $campaign, $this->context( [], 0.0, [], 1.0, 400 ) ) );
    }
}
