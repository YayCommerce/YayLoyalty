<?php
namespace YayLoyalty\Tests\Unit\Engine\Expiry;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Expiry\ExpiryDates;

/** Expiry date maths: days after activity, months after earning with month-end clamping, and the grace floor. */
final class ExpiryDatesTest extends TestCase {

    public function test_inactivity_adds_days(): void {
        $this->assertSame( '2027-09-01 10:00:00', ExpiryDates::inactivity_expires_at( '2026-09-01 10:00:00', 365 ) );
    }

    public function test_batch_adds_months_and_clamps_to_month_end(): void {
        $this->assertSame( '2026-02-28 08:00:00', ExpiryDates::batch_expires_at( '2026-01-31 08:00:00', 1 ) );
        $this->assertSame( '2028-12-15 00:00:00', ExpiryDates::batch_expires_at( '2027-12-15 00:00:00', 12 ) );
    }

    public function test_floor_returns_the_later_date(): void {
        $this->assertSame( '2026-11-12 00:00:00', ExpiryDates::floor_at( '2026-10-20 00:00:00', '2026-11-12 00:00:00' ) );
        $this->assertSame( '2026-11-12 00:00:00', ExpiryDates::floor_at( '2026-11-12 00:00:00', '2026-10-20 00:00:00' ) );
    }
}
