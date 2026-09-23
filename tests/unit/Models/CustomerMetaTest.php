<?php
namespace YayLoyalty\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Models\CustomerMeta;

/**
 * The JSON envelope round-trip and the activity wire shape `to_array()`
 * promises `ActivityRestController`'s item schema.
 */
final class CustomerMetaTest extends TestCase {

    public function test_to_row_and_from_row_round_trip(): void {
        $model = CustomerMeta::event(
            42,
            'points_adjusted',
            [
                'points' => -100,
                'note'   => 'goodwill credit',
            ],
            'staff',
            'Jane Admin',
            true,
            '2026-09-22 10:00:00'
        );

        $rehydrated = CustomerMeta::from_row( $model->to_row() + [ 'id' => 7 ] );

        $this->assertSame( 7, $rehydrated->get_id() );
        $this->assertSame( 42, $rehydrated->get_user_id() );
        $this->assertSame( 'points_adjusted', $rehydrated->get_meta_key() );
        $this->assertSame(
            [
                'points' => -100,
                'note'   => 'goodwill credit',
            ],
            $rehydrated->get_data()
        );
        $this->assertSame( '2026-09-22 10:00:00', $rehydrated->get_created_at() );
    }

    public function test_to_array_emits_exactly_the_activity_item_keys(): void {
        $model = CustomerMeta::event(
            1,
            'account_created',
            [
                'points' => 10,
                'source' => 'order',
            ],
            'automatic',
            null,
            false,
            '2026-01-01 00:00:00'
        );

        $wire = CustomerMeta::from_row( $model->to_row() + [ 'id' => 1 ] )->to_array();

        $this->assertSame(
            [ 'id', 'occurred_at', 'event_type', 'data', 'actor_kind', 'actor_name', 'staff_only' ],
            array_keys( $wire )
        );
        $this->assertArrayNotHasKey( 'schema_version', $wire['data'] );
    }

    public function test_corrupt_meta_value_degrades_to_empty_data_without_throwing(): void {
        $model = CustomerMeta::from_row(
            [
                'id'         => 1,
                'user_id'    => 1,
                'meta_key'   => 'account_created',
                'meta_value' => 'not json at all {{{',
                'created_at' => '2026-01-01 00:00:00',
            ]
        );

        $this->assertSame( [], $model->get_data() );
        $this->assertSame( 'account_created', $model->get_meta_key() );
    }
}
