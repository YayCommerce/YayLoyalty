<?php
namespace YayTests\Unit\Models;

use Brain\Monkey\Functions;
use YayLoyalty\Models\CampaignConditionSet;
use YayLoyalty\Utils\ConditionMatch;
use YayLoyalty\Utils\Json;
use YayTests\Unit\TestCase;

/**
 * @group P-1
 */
final class CampaignConditionSetTest extends TestCase {

    protected function set_up() {
        parent::set_up();
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
    }

    public function test_a_stored_flat_list_reads_as_all_and_re_encodes_byte_identical() {
        // As 1.0.0 wrote it, deliberately not in normalize() order.
        $stored = '{"items":[{"type":"order_count","operator":"gte","value":5},{"type":"user_role","operator":"in","value":["customer"]}],"schema_version":1}';

        $parsed = ConditionMatch::decode( $stored );

        $this->assertSame( ConditionMatch::MATCH_ALL, $parsed['match'] );
        $this->assertSame( 'order_count', $parsed['conditions'][0]['type'] );

        $this->assertSame( $stored, ConditionMatch::encode( $parsed, 1 ) );
        $this->assertSame( $stored, CampaignConditionSet::from_json( $stored )->to_json() );
    }

    public function test_no_conditions_still_encode_as_the_empty_list_envelope() {
        $this->assertSame( '{"items":[],"schema_version":1}', ConditionMatch::encode( [], 1 ) );
        $this->assertSame(
            '{"items":[],"schema_version":1}',
            ConditionMatch::encode(
                [
                    'match'      => 'any',
                    'conditions' => [],
                ],
                1
            )
        );
        $this->assertSame( [], ConditionMatch::decode( '{"items":[],"schema_version":1}' )['conditions'] );
    }

    public function test_an_any_list_round_trips_as_schema_version_2_and_a_list_reader_still_sees_its_items() {
        $parsed = CampaignConditionSet::normalize( $this->any_list() );

        $encoded = ConditionMatch::encode( $parsed, 1 );

        $this->assertStringContainsString( '"schema_version":2', $encoded );
        $this->assertStringContainsString( '"match":"any"', $encoded );
        $this->assertSame( $parsed, ConditionMatch::decode( $encoded ) );
        $this->assertSame( $parsed['conditions'], Json::decode_list( $encoded ) );
        $this->assertTrue( CampaignConditionSet::is_valid( $parsed ) );
    }

    public function test_a_type_may_not_repeat_under_either_match() {
        $twice = [ $this->order_count( 5 ), $this->order_count( 10 ) ];

        $this->assertFalse( CampaignConditionSet::is_valid( $twice ) );
        $this->assertFalse(
            CampaignConditionSet::is_valid(
                [
                    'match'      => 'any',
                    'conditions' => $twice,
                ]
            )
        );
    }

    public function test_an_unknown_match_or_a_non_list_is_rejected() {
        $this->assertFalse(
            CampaignConditionSet::is_valid(
                [
                    'match'      => 'xor',
                    'conditions' => [ $this->order_count( 1 ) ],
                ]
            )
        );
        $this->assertFalse(
            CampaignConditionSet::is_valid(
                [
                    'match'      => 'all',
                    'conditions' => 'nope',
                ]
            )
        );
    }

    /** Lifetime spend >= 500 USD OR order count >= 5. */
    private function any_list(): array {
        return [
            'match'      => 'any',
            'conditions' => [
                [
                    'type'     => 'lifetime_spend',
                    'operator' => 'gte',
                    'value'    => [
                        'amount'   => 500,
                        'currency' => 'USD',
                    ],
                ],
                $this->order_count( 5 ),
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
