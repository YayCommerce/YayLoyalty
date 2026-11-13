<?php
namespace YayTests\Unit\Models;

use Brain\Monkey\Functions;
use YayLoyalty\Models\CampaignConditionSet;
use YayLoyalty\Utils\ConditionGroups;
use YayTests\Unit\TestCase;

/**
 * @group P-1
 */
final class CampaignConditionSetTest extends TestCase {

	protected function set_up() {
		parent::set_up();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	public function test_a_stored_flat_list_reads_as_one_all_group_and_re_encodes_byte_identical() {
		// As 1.0.0 wrote it, deliberately not in normalize() order.
		$stored = '{"items":[{"type":"order_count","operator":"gte","value":5},{"type":"user_role","operator":"in","value":["customer"]}],"schema_version":1}';

		$tree = ConditionGroups::decode( $stored );

		$this->assertSame( ConditionGroups::MATCH_ALL, $tree['match'] );
		$this->assertCount( 1, $tree['groups'] );
		$this->assertSame( ConditionGroups::MATCH_ALL, $tree['groups'][0]['match'] );
		$this->assertSame( 'order_count', $tree['groups'][0]['conditions'][0]['type'] );

		$this->assertSame( $stored, ConditionGroups::encode( $tree, 1 ) );
		$this->assertSame( $stored, CampaignConditionSet::from_json( $stored )->to_json() );
	}

	public function test_no_conditions_still_encode_as_the_empty_list_envelope() {
		$this->assertSame( '{"items":[],"schema_version":1}', ConditionGroups::encode( [], 1 ) );
		$this->assertSame( [], ConditionGroups::decode( '{"items":[],"schema_version":1}' )['groups'] );
	}

	public function test_a_grouped_tree_round_trips_as_schema_version_2() {
		$tree = CampaignConditionSet::normalize( $this->acceptance_tree() );

		$encoded = ConditionGroups::encode( $tree, 1 );

		$this->assertStringContainsString( '"schema_version":2', $encoded );
		$this->assertStringNotContainsString( '"items"', $encoded );
		$this->assertSame( $tree, ConditionGroups::decode( $encoded ) );
		$this->assertTrue( CampaignConditionSet::is_valid( $tree ) );
	}

	public function test_normalize_drops_empty_groups() {
		$tree = CampaignConditionSet::normalize(
			[
				'match'  => 'any',
				'groups' => [
					[ 'match' => 'all', 'conditions' => [] ],
					[ 'match' => 'any', 'conditions' => [ $this->order_count( 5 ) ] ],
					[ 'match' => 'all' ],
				],
			]
		);

		$this->assertCount( 1, $tree['groups'] );
		$this->assertSame( 'any', $tree['groups'][0]['match'] );
	}

	public function test_a_type_may_repeat_across_groups_but_not_within_one() {
		$across = [
			'match'  => 'any',
			'groups' => [
				[ 'match' => 'all', 'conditions' => [ $this->order_count( 5 ) ] ],
				[ 'match' => 'all', 'conditions' => [ $this->order_count( 10 ) ] ],
			],
		];
		$within = [
			'match'  => 'all',
			'groups' => [
				[ 'match' => 'any', 'conditions' => [ $this->order_count( 5 ), $this->order_count( 10 ) ] ],
			],
		];

		$this->assertTrue( CampaignConditionSet::is_valid( $across ) );
		$this->assertFalse( CampaignConditionSet::is_valid( $within ) );
		$this->assertFalse( CampaignConditionSet::is_valid( [ $this->order_count( 5 ), $this->order_count( 10 ) ] ) );
	}

	public function test_an_eleventh_group_or_an_unknown_match_is_rejected() {
		$group = [ 'match' => 'all', 'conditions' => [ $this->order_count( 1 ) ] ];

		$this->assertTrue( CampaignConditionSet::is_valid( [ 'match' => 'any', 'groups' => array_fill( 0, 10, $group ) ] ) );
		$this->assertFalse( CampaignConditionSet::is_valid( [ 'match' => 'any', 'groups' => array_fill( 0, 11, $group ) ] ) );
		$this->assertFalse( CampaignConditionSet::is_valid( [ 'match' => 'xor', 'groups' => [ $group ] ] ) );
		$this->assertFalse( CampaignConditionSet::is_valid( [ 'match' => 'all', 'groups' => [ [ 'match' => 'none', 'conditions' => [ $this->order_count( 1 ) ] ] ] ] ) );
		$this->assertFalse( CampaignConditionSet::is_valid( [ 'match' => 'all', 'groups' => 'nope' ] ) );
	}

	/** Country US (billing) AND ( lifetime spend >= 500 USD OR order count >= 5 ). */
	private function acceptance_tree(): array {
		return [
			'match'  => 'all',
			'groups' => [
				[
					'match'      => 'all',
					'conditions' => [
						[
							'type'     => 'country',
							'operator' => 'in',
							'value'    => [ 'countries' => [ 'US' ], 'source' => 'billing' ],
						],
					],
				],
				[
					'match'      => 'any',
					'conditions' => [
						[
							'type'     => 'lifetime_spend',
							'operator' => 'gte',
							'value'    => [ 'amount' => 500, 'currency' => 'USD' ],
						],
						$this->order_count( 5 ),
					],
				],
			],
		];
	}

	private function order_count( int $count ): array {
		return [
			'type'     => 'order_count',
			'operator' => 'gte',
			'value'    => $count,
		];
	}
}
