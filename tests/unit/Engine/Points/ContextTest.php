<?php
namespace YayLoyalty\Tests\Unit\Engine\Points;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Points\Context;

/**
 * Which coupon codes are our own: only a reward's stored code, so earning still counts every merchant coupon.
 */
final class ContextTest extends TestCase {

    public function test_a_generated_code_is_a_loyalty_code(): void {
        $this->assertTrue( Context::is_loyalty_code( 'yayloyalty-ab12cd34' ) );
    }

    public function test_other_codes_are_not_loyalty_codes(): void {
        foreach ( [ 'summer10', 'yayloyalty-redeem', 'yayloyalty-reward-5', 'yayloyalty-AB12CD34', 'yayloyalty-ab12cd345', 'xyayloyalty-ab12cd34' ] as $code ) {
            $this->assertFalse( Context::is_loyalty_code( $code ), $code );
        }
    }
}
