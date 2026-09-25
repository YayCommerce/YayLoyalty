<?php
namespace YayLoyalty\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;

/**
 * to_update_row() pairs to_row()'s data with get_format()'s placeholders
 * position-for-position; $wpdb->update() consumes $format with array_shift(),
 * so a mismatch after dropping the immutable columns would silently bind the
 * wrong type to the wrong column. See the method's own docblock.
 */
final class CampaignToUpdateRowTest extends TestCase {

    private function make_campaign(): Campaign {
        return new Campaign(
            7,
            'Trail Shoes boost',
            'trail-shoes-boost',
            CampaignTrigger::PURCHASE,
            Status::ACTIVE,
            80,
            Campaign::default_config( CampaignTrigger::PURCHASE ),
            [],
            null,
            null,
            false,
            5,
            '2026-06-01 00:00:00',
            '2026-09-01 00:00:00',
            9,
            null
        );
    }

    public function test_immutable_columns_are_absent_from_both_data_and_format(): void {
        $update = $this->make_campaign()->to_update_row();

        foreach ( [ 'slug', 'trigger_type', 'created_at', 'created_by', 'is_builtin' ] as $column ) {
            $this->assertArrayNotHasKey( $column, $update['data'], $column );
        }

        // One format entry per remaining data column — the guarantee that matters.
        $this->assertCount( count( $update['data'] ), $update['format'] );
    }

    public function test_each_format_placeholder_still_matches_its_own_column(): void {
        $update = $this->make_campaign()->to_update_row();
        $keys   = array_keys( $update['data'] );

        $expected = [
            'name'        => '%s',
            'status'      => '%s',
            'priority'    => '%d',
            'config'      => '%s',
            'conditions'  => '%s',
            'starts_at'   => '%s',
            'ends_at'     => '%s',
            'updated_by'  => '%d',
            'updated_at'  => '%s',
            'archived_at' => '%s',
        ];

        $this->assertSame( array_keys( $expected ), $keys );

        foreach ( $keys as $index => $key ) {
            $this->assertSame( $expected[ $key ], $update['format'][ $index ], $key );
        }
    }

    public function test_mutable_values_survive_the_round_trip(): void {
        $campaign = $this->make_campaign();
        $campaign->set_priority( 42 );

        $update = $campaign->to_update_row();

        $this->assertSame( 42, $update['data']['priority'] );
        $this->assertSame( '2026-09-01 00:00:00', $update['data']['updated_at'] );
        $this->assertSame( 9, $update['data']['updated_by'] );
    }
}
