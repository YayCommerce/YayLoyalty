<?php
namespace YayLoyalty\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Utils\PointsMath;

/** round_points() on raw values whose true result is whole but whose float lands just off it. */
final class PointsMathTest extends TestCase {

    /** @return array<string, array{float, int, int, int}> raw, then the down, nearest and up results. */
    public function raw_points(): array {
        return [
            'just below a whole number' => [ 0.57 * 100, 57, 57, 57 ],
            'just above a whole number' => [ 0.07 * 100, 7, 7, 7 ],
            'spend 3 to receive 1'      => [ ( 1 / 3 ) * 3, 1, 1, 1 ],
            'a half'                    => [ 2.5, 2, 3, 3 ],
            'a fraction'                => [ 0.3 * 3, 0, 1, 1 ],
            'zero'                      => [ 0.0, 0, 0, 0 ],
        ];
    }

    /** @dataProvider raw_points */
    public function test_round_points_lands_on_the_true_whole_number( float $raw, int $down, int $nearest, int $up ): void {
        $this->assertSame( $down, PointsMath::round_points( $raw, PointsMath::ROUND_DOWN ) );
        $this->assertSame( $nearest, PointsMath::round_points( $raw, PointsMath::ROUND_NEAREST ) );
        $this->assertSame( $up, PointsMath::round_points( $raw, PointsMath::ROUND_UP ) );
    }
}
