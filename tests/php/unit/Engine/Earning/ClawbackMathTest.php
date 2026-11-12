<?php
namespace YayTests\Unit\Engine\Earning;

use YayLoyalty\Engine\Earning\ClawbackMath;
use YayTests\Unit\TestCase;

/**
 * @group baseline
 */
final class ClawbackMathTest extends TestCase {

	public function test_claws_back_the_refunded_share_of_the_earn() {
		$this->assertSame( 3, ClawbackMath::owed( 10, 3.0, 10.0, false ) );
	}

	public function test_floors_a_partial_share_so_it_never_claws_back_too_much() {
		$this->assertSame( 3, ClawbackMath::owed( 10, 3.9, 10.0, false ) );
	}

	public function test_takes_the_whole_earn_on_a_full_or_over_refund() {
		$this->assertSame( 10, ClawbackMath::owed( 10, 0.0, 10.0, true ) );
		$this->assertSame( 10, ClawbackMath::owed( 10, 12.0, 10.0, false ) );
	}

	public function test_owes_nothing_when_the_counted_lines_had_no_base() {
		$this->assertSame( 0, ClawbackMath::owed( 10, 0.0, 0.0, false ) );
		$this->assertSame( 10, ClawbackMath::owed( 10, 0.0, 0.0, true ) );
	}
}
