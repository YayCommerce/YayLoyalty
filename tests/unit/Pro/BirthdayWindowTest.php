<?php
namespace YayLoyalty\Tests\Unit\Pro;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Pro\Birthday\BirthdayWindow;

/** The birthday date maths: which year a birthday is paid under, and which days are due today. */
final class BirthdayWindowTest extends TestCase {

    public function test_next_birthday_is_today(): void {
        $this->assertSame(
            [
                'year'       => 2026,
                'days_until' => 0,
            ],
            BirthdayWindow::next( 10, 19, new \DateTimeImmutable( '2026-10-19' ) )
        );
    }

    public function test_next_birthday_already_passed_rolls_to_next_year(): void {
        $this->assertSame(
            [
                'year'       => 2027,
                'days_until' => 364,
            ],
            BirthdayWindow::next( 10, 18, new \DateTimeImmutable( '2026-10-19' ) )
        );
    }

    public function test_next_birthday_across_new_year(): void {
        $this->assertSame(
            [
                'year'       => 2027,
                'days_until' => 4,
            ],
            BirthdayWindow::next( 1, 2, new \DateTimeImmutable( '2026-12-29' ) )
        );
    }

    public function test_feb_29_falls_on_feb_28_in_a_non_leap_year(): void {
        $this->assertSame(
            [
                'year'       => 2027,
                'days_until' => 0,
            ],
            BirthdayWindow::next( 2, 29, new \DateTimeImmutable( '2027-02-28' ) )
        );
        $this->assertSame(
            [
                'year'       => 2028,
                'days_until' => 0,
            ],
            BirthdayWindow::next( 2, 29, new \DateTimeImmutable( '2028-02-29' ) )
        );
    }

    public function test_due_days_spans_new_year(): void {
        $this->assertSame(
            [
                [
                    'month' => 12,
                    'day'   => 30,
                ],
                [
                    'month' => 12,
                    'day'   => 31,
                ],
                [
                    'month' => 1,
                    'day'   => 1,
                ],
            ],
            BirthdayWindow::due_days( new \DateTimeImmutable( '2026-12-30' ), 2 )
        );
    }

    public function test_due_days_adds_feb_29_in_a_non_leap_year(): void {
        $this->assertSame(
            [
                [
                    'month' => 2,
                    'day'   => 28,
                ],
                [
                    'month' => 2,
                    'day'   => 29,
                ],
            ],
            BirthdayWindow::due_days( new \DateTimeImmutable( '2027-02-28' ), 0 )
        );
    }
}
