<?php
namespace YayTests\Unit\Pro\Services;

use Brain\Monkey\Functions;
use YayLoyalty\Engine\Points\CustomerFacts;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Models\Reward;
use YayLoyalty\Pro\Services\RewardConditionsEvaluator;
use YayTests\Unit\TestCase;

/**
 * @group P-1
 */
final class RewardConditionsEvaluatorTest extends TestCase {

	/** @var int */
	private $facts_reads = 0;

	protected function set_up() {
		parent::set_up();
		$this->facts_reads = 0;

		Functions\when( 'get_userdata' )->alias(
			static function ( $user_id ) {
				$roles = [
					7 => [ 'customer' ],
					8 => [ 'wholesale' ],
				];

				return isset( $roles[ $user_id ] ) ? (object) [ 'roles' => $roles[ $user_id ] ] : false;
			}
		);
	}

	public function test_a_role_condition_decides_redeemability() {
		$reward = $this->reward( [ $this->role_in( 'wholesale' ) ] );

		$this->assertTrue( $this->redeemable( $reward, $this->ctx( 8 ) ) );
		$this->assertFalse( $this->redeemable( $reward, $this->ctx( 7 ) ) );
	}

	public function test_a_specific_customers_condition_decides_redeemability() {
		$reward = $this->reward(
			[
				[
					'type'     => 'customer_ids',
					'operator' => 'in',
					'value'    => [ 7 ],
				],
			]
		);

		$this->assertTrue( $this->redeemable( $reward, $this->ctx( 7 ) ) );
		$this->assertFalse( $this->redeemable( $reward, $this->ctx( 8 ) ) );
	}

	public function test_ored_groups_of_role_and_lifetime_points_decide_redeemability() {
		$reward = $this->reward(
			[
				'match'  => 'any',
				'groups' => [
					[ 'match' => 'all', 'conditions' => [ $this->role_in( 'wholesale' ) ] ],
					[ 'match' => 'all', 'conditions' => [ $this->lifetime_points_at_least( 1000 ) ] ],
				],
			]
		);

		$this->assertTrue( $this->redeemable( $reward, $this->ctx( 8, 0 ) ) );
		$this->assertTrue( $this->redeemable( $reward, $this->ctx( 7, 1500 ) ) );
		$this->assertFalse( $this->redeemable( $reward, $this->ctx( 7, 10 ) ) );
	}

	public function test_a_guest_fails_a_conditional_reward_and_passes_an_unconditional_one() {
		$this->assertFalse( $this->redeemable( $this->reward( [ $this->role_in( 'customer' ) ] ), $this->ctx( null ) ) );
		$this->assertTrue( $this->redeemable( $this->reward( [] ), $this->ctx( null ) ) );
	}

	public function test_an_earlier_refusal_stays_a_refusal() {
		$this->assertFalse( ( new RewardConditionsEvaluator() )->matches( false, $this->reward( [] ), $this->ctx( 7 ) ) );
	}

	public function test_the_facts_loader_runs_only_for_a_lifetime_points_condition_and_once() {
		$this->redeemable( $this->reward( [ $this->role_in( 'customer' ) ] ), $this->ctx( 7, 5000 ) );
		$this->assertSame( 0, $this->facts_reads );

		$ctx    = $this->ctx( 7, 5000 );
		$copy   = $ctx->with_balance( 10 );
		$reward = $this->reward( [ $this->lifetime_points_at_least( 1000 ) ] );

		$this->assertTrue( $this->redeemable( $reward, $ctx ) );
		$this->assertTrue( $this->redeemable( $reward, $copy ) );
		$this->assertTrue( $this->redeemable( $reward, $ctx ) );
		$this->assertSame( 1, $this->facts_reads );
	}

	private function redeemable( Reward $reward, Context $ctx ): bool {
		return ( new RewardConditionsEvaluator() )->matches( true, $reward, $ctx );
	}

	private function ctx( ?int $user_id, int $lifetime_points = 0 ): Context {
		$loader = function () use ( $user_id, $lifetime_points ) {
			++$this->facts_reads;

			return null === $user_id ? null : new CustomerFacts( $lifetime_points, 0, [] );
		};

		return new Context( $user_id, 5000, null, null, 'USD', '2026-11-13 00:00:00', $loader );
	}

	private function reward( array $conditions ): Reward {
		return new Reward( 1, 'Wholesale $5', 'wholesale-5', 'fixed_coupon', 'active', 0, 100, [], $conditions );
	}

	private function role_in( string $role ): array {
		return [
			'type'     => 'user_role',
			'operator' => 'in',
			'value'    => [ $role ],
		];
	}

	private function lifetime_points_at_least( int $points ): array {
		return [
			'type'     => 'lifetime_points',
			'operator' => 'gte',
			'value'    => $points,
		];
	}
}
