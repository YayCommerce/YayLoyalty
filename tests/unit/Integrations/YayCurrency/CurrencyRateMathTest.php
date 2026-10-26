<?php
namespace YayLoyalty\Tests\Unit\Integrations\YayCurrency;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Integrations\YayCurrency\CurrencyRateMath;

/** The rate resolution order and YayCurrency's exchange fee, the two places a wrong rate would misprice every point. */
final class CurrencyRateMathTest extends TestCase {

    public function test_the_saved_order_rate_wins(): void {
        $this->assertSame( 0.00004, CurrencyRateMath::resolve( '0.0000400000', 0.00005 ) );
    }

    public function test_the_live_rate_applies_without_a_saved_rate(): void {
        $this->assertSame( 0.00005, CurrencyRateMath::resolve( null, 0.00005 ) );
    }

    public function test_no_rate_resolves_to_one(): void {
        $this->assertSame( 1.0, CurrencyRateMath::resolve( null, null ) );
    }

    public function test_unusable_rates_are_skipped(): void {
        $this->assertSame( 0.00005, CurrencyRateMath::resolve( '0', 0.00005 ) );
        $this->assertSame( 0.00005, CurrencyRateMath::resolve( 'abc', 0.00005 ) );
        $this->assertSame( 1.0, CurrencyRateMath::resolve( '-1', -2.0 ) );
        $this->assertSame( 1.0, CurrencyRateMath::resolve( '', 0.0 ) );
    }

    public function test_a_percentage_fee_is_added_when_included(): void {
        $fee = [
            'type'  => 'percentage',
            'value' => '10',
        ];

        $this->assertEqualsWithDelta( 0.000044, CurrencyRateMath::fee_rate( 0.00004, $fee, true ), 1e-12 );
        $this->assertSame( 0.00004, CurrencyRateMath::fee_rate( 0.00004, $fee, false ) );
    }

    public function test_a_fixed_fee_is_added_when_included(): void {
        $fee = [
            'type'  => 'fixed',
            'value' => '0.00001',
        ];

        $this->assertEqualsWithDelta( 0.00005, CurrencyRateMath::fee_rate( 0.00004, $fee, true ), 1e-12 );
        $this->assertSame( 0.00004, CurrencyRateMath::fee_rate( 0.00004, $fee, false ) );
    }

    public function test_a_missing_fee_adds_nothing(): void {
        $this->assertSame( 0.00004, CurrencyRateMath::fee_rate( 0.00004, [], true ) );
    }
}
