<?php
namespace YayLoyalty\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Utils\Sql;

/**
 * `in_placeholders()` is the one piece of SQL-building this repo shares
 * across `CustomerMetaRepository`, `DashboardRepository` and
 * `Pro\Repositories\CampaignPointsRepository` — pure, no `$wpdb`, so the bug
 * class it guards against (a miscounted placeholder list vs bound values)
 * is unit-testable without WordPress.
 */
final class SqlTest extends TestCase {

    public function test_placeholder_count_matches_input_count(): void {
        $this->assertSame( '', Sql::in_placeholders( [] ) );
        $this->assertSame( '%s', Sql::in_placeholders( [ 'account_created' ] ) );
        $this->assertSame(
            '%s, %s, %s',
            Sql::in_placeholders( [ 'account_created', 'points_adjusted', 'account_deleted' ] )
        );
    }

    public function test_placeholder_defaults_to_percent_s(): void {
        $this->assertSame( '%s, %s', Sql::in_placeholders( [ 1, 2 ] ) );
    }

    public function test_placeholder_accepts_an_explicit_format(): void {
        $this->assertSame( '%d, %d, %d', Sql::in_placeholders( [ 1, 2, 3 ], '%d' ) );
    }
}
