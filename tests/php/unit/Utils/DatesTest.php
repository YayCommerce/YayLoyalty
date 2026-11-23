<?php
namespace YayTests\Unit\Utils;

use Brain\Monkey\Functions;
use YayLoyalty\Utils\Dates;
use YayTests\Unit\TestCase;

/**
 * @group B-1
 */
final class DatesTest extends TestCase {

    protected function set_up() {
        parent::set_up();
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
    }

    public function test_an_impossible_day_gives_no_bound() {
        $this->assertNull( Dates::site_day_to_utc( '2026-02-30' ) );
        $this->assertNull( Dates::site_day_to_utc( '2026-13-01' ) );
        $this->assertNull( Dates::site_day_to_utc( '2026-2-3' ) );
    }

    public function test_a_real_day_still_gives_its_utc_bound() {
        $this->assertSame( '2026-02-28 00:00:00', Dates::site_day_to_utc( '2026-02-28' ) );
        $this->assertSame( '2026-03-01 00:00:00', Dates::site_day_to_utc( '2026-02-28', '+1 day' ) );
    }
}
