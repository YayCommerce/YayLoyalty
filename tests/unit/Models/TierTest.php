<?php
namespace YayLoyalty\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Models\Tier;

/**
 * The normalizers TiersRestController runs on every tier write. The multiplier is read as whole
 * hundredths, never through a float, because it will scale the points every campaign awards.
 */
final class TierTest extends TestCase {

    /** @return array<string, array{mixed, string}> */
    public function accepted_multipliers(): array {
        return [
            'one decimal'         => [ '1.3', '1.30' ],
            'a JSON number'       => [ 1.25, '1.25' ],
            'the maximum, string' => [ '10', '10.00' ],
            'the maximum, int'    => [ 10, '10.00' ],
            'the minimum'         => [ '1.00', '1.00' ],
            'a leading zero'      => [ '01.50', '1.50' ],
            'a step of 0.05'      => [ '1.15', '1.15' ],
        ];
    }

    /**
     * @dataProvider accepted_multipliers
     * @param mixed $input
     */
    public function test_multiplier_is_returned_as_a_two_decimal_string( $input, string $expected ): void {
        $this->assertSame( $expected, Tier::normalize_multiplier( $input ) );
    }

    /** @return array<string, array{mixed}> */
    public function refused_multipliers(): array {
        return [
            'above the maximum'           => [ '10.05' ],
            'below the minimum'           => [ '0.95' ],
            'off the 0.05 step'           => [ '1.27' ],
            'three decimals'              => [ '1.234' ],
            'a three-decimal JSON number' => [ 1.234 ],
            'a trailing point'            => [ '1.' ],
            'no whole part'               => [ '.5' ],
            'not a number'                => [ 'abc' ],
            'null'                        => [ null ],
        ];
    }

    /**
     * @dataProvider refused_multipliers
     * @param mixed $input
     */
    public function test_multiplier_outside_the_rules_is_refused( $input ): void {
        $this->assertNull( Tier::normalize_multiplier( $input ) );
    }

    public function test_threshold_and_entry_bonus_accept_whole_numbers_within_bounds(): void {
        $this->assertSame( 0, Tier::normalize_threshold( 0 ) );
        $this->assertSame( 800, Tier::normalize_threshold( '800' ) );
        $this->assertSame( Tier::THRESHOLD_MAX, Tier::normalize_threshold( 1000000000 ) );
        $this->assertSame( 0, Tier::normalize_entry_bonus( 0 ) );
        $this->assertSame( Tier::ENTRY_BONUS_MAX, Tier::normalize_entry_bonus( 1000000 ) );
    }

    public function test_threshold_and_entry_bonus_refuse_values_outside_the_rules(): void {
        $this->assertNull( Tier::normalize_threshold( -1 ) );
        $this->assertNull( Tier::normalize_threshold( 1000000001 ) );
        $this->assertNull( Tier::normalize_threshold( 1.5 ) );
        $this->assertNull( Tier::normalize_threshold( '1e3' ) );
        $this->assertNull( Tier::normalize_entry_bonus( -1 ) );
        $this->assertNull( Tier::normalize_entry_bonus( 1000001 ) );
    }

    public function test_colour_must_be_six_hex_digits_and_comes_back_uppercase(): void {
        $this->assertSame( '#E5E4E2', Tier::normalize_color( '#e5e4e2' ) );
        $this->assertNull( Tier::normalize_color( '#FFF' ) );
        $this->assertNull( Tier::normalize_color( 'C0C0C0' ) );
    }

    public function test_name_is_trimmed_and_bounded(): void {
        $this->assertSame( 'Gold', Tier::normalize_name( '  Gold ' ) );
        $this->assertNull( Tier::normalize_name( '   ' ) );
        $this->assertNull( Tier::normalize_name( str_repeat( 'a', Tier::NAME_MAX + 1 ) ) );
        $this->assertSame( str_repeat( 'a', Tier::NAME_MAX ), Tier::normalize_name( str_repeat( 'a', Tier::NAME_MAX ) ) );
    }
}
