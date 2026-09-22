<?php
namespace YayLoyalty\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The award-path Context carries the order's own time. Needs WC_Order — specification only. */
final class ContextFactoryTest extends TestCase {

    public function test_from_order_uses_the_order_creation_date_as_occurred_at(): void {
        $this->markTestIncomplete( 'occurred_at equals get_date_created() in UTC, whatever the wall clock says.' );
    }

    public function test_from_order_throws_when_the_order_has_no_creation_date(): void {
        $this->markTestIncomplete( 'No get_date_created() → InvalidArgumentException; never Dates::now().' );
    }

    public function test_from_order_builds_the_same_context_at_creation_and_at_award(): void {
        $this->markTestIncomplete( 'Two calls for one unchanged order, same points_earned_today, produce equal Contexts.' );
    }
}
