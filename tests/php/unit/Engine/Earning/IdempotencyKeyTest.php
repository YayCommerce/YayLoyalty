<?php
namespace YayTests\Unit\Engine\Earning;

use YayLoyalty\Engine\Earning\IdempotencyKey;
use YayTests\Unit\TestCase;

/**
 * @group baseline
 */
final class IdempotencyKeyTest extends TestCase {

	public function test_composes_one_key_per_user_reference_and_qualifier() {
		$this->assertSame( 'earn:7:order:1234:purchase', IdempotencyKey::for_earn( 7, 'order', 1234, 'purchase' ) );
		$this->assertSame( 'redeem:7:order:1234:reward_5', IdempotencyKey::compose( 'redeem', 7, 'order', 1234, 'reward_5' ) );
		$this->assertSame( 'expire:1:user:1:20261112', IdempotencyKey::compose( 'expire', 1, 'user', 1, '20261112' ) );
	}

	public function test_still_composes_a_pro_trigger_on_a_lite_site() {
		$this->assertSame( 'earn:7:user:7:birthday', IdempotencyKey::for_earn( 7, 'user', 7, 'birthday' ) );
	}

	/**
	 * @dataProvider invalid_parts
	 */
	public function test_refuses_a_part_outside_its_vocabulary( string $type, int $user_id, string $reference_type, int $reference_id, string $qualifier ) {
		$this->expectException( \InvalidArgumentException::class );

		IdempotencyKey::compose( $type, $user_id, $reference_type, $reference_id, $qualifier );
	}

	public function invalid_parts(): array {
		return [
			'unknown ledger type'          => [ 'bogus', 7, 'order', 1, 'purchase' ],
			'no user'                      => [ 'earn', 0, 'order', 1, 'purchase' ],
			'unknown reference type'       => [ 'earn', 7, 'bogus', 1, 'purchase' ],
			'no reference id'              => [ 'earn', 7, 'order', 0, 'purchase' ],
			'reward type as earn trigger'  => [ 'earn', 7, 'order', 1, 'flexible_discount' ],
			'reward id zero'               => [ 'redeem', 7, 'order', 1, 'reward_0' ],
			'reward id with leading zero'  => [ 'redeem', 7, 'order', 1, 'reward_05' ],
			'reward id with trailing line' => [ 'redeem', 7, 'order', 1, "reward_5\n" ],
			'expire day with dashes'       => [ 'expire', 7, 'user', 7, '2026-11-12' ],
		];
	}

	public function test_reads_the_qualifier_back_from_a_key() {
		$this->assertSame( 'purchase', IdempotencyKey::qualifier_of( 'earn:7:order:1234:purchase' ) );
	}

	public function test_refuses_to_read_a_qualifier_from_a_malformed_key() {
		$this->expectException( \InvalidArgumentException::class );

		IdempotencyKey::qualifier_of( 'earn:7:order:1234:' );
	}
}
