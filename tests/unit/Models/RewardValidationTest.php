<?php
namespace YayLoyalty\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;
use YayLoyalty\Pro\YayLoyaltyPro;

/**
 * The checks RewardRestController runs before a row is written: the Pro
 * types' typed config specs, the bounds they rely on, and to_update_row()'s
 * column/format pairing.
 */
final class RewardValidationTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['yayloyalty_test_hooks'] = [];
        add_filter( 'yayloyalty_reward_config_schema', [ YayLoyaltyPro::class, 'add_reward_config_schema' ] );
    }

    protected function tearDown(): void {
        $GLOBALS['yayloyalty_test_hooks'] = [];
        parent::tearDown();
    }

    private function fixed_coupon( array $overrides = [] ): array {
        return array_merge(
            [
                'currency'           => 'USD',
                'amount'             => 5,
                'min_order'          => 25,
                'coupon_expiry_days' => 60,
                'exclude_sale_items' => false,
                'max_total'          => null,
            ],
            $overrides
        );
    }

    public function test_valid_configs_pass_for_every_pro_type(): void {
        $this->assertTrue( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon() ) );
        $this->assertTrue(
            Reward::config_is_valid(
                RewardType::PERCENT_COUPON,
                [
                    'percent'            => 15,
                    'max_discount'       => null,
                    'currency'           => 'USD',
                    'min_order'          => 0,
                    'coupon_expiry_days' => 30,
                    'exclude_sale_items' => true,
                    'max_total'          => 100,
                ]
            )
        );
        $this->assertTrue(
            Reward::config_is_valid(
                RewardType::FREE_SHIPPING,
                [
                    'currency'  => 'USD',
                    'min_order' => null,
                ]
            )
        );
        $this->assertTrue(
            Reward::config_is_valid(
                RewardType::FREE_PRODUCT,
                [
                    'product_id'   => 12,
                    'variation_id' => null,
                    'qty'          => 1,
                ]
            )
        );
    }

    public function test_out_of_bounds_values_are_rejected(): void {
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'amount' => 0 ] ) ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'amount' => 'five' ] ) ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'min_order' => -1 ] ) ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'coupon_expiry_days' => 0 ] ) ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'exclude_sale_items' => 'yes' ] ) ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'max_total' => 2.5 ] ) ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'max_total' => 0 ] ) ) );

        $percent = [
            'percent'  => 150,
            'currency' => 'USD',
        ];
        $this->assertFalse( Reward::config_is_valid( RewardType::PERCENT_COUPON, $percent ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::PERCENT_COUPON, [ 'percent' => 0 ] + $percent ) );
        $this->assertTrue( Reward::config_is_valid( RewardType::PERCENT_COUPON, [ 'percent' => 100 ] + $percent ) );

        $product = [
            'product_id' => 12,
            'qty'        => 0,
        ];
        $this->assertFalse( Reward::config_is_valid( RewardType::FREE_PRODUCT, $product ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FREE_PRODUCT, [ 'product_id' => 0 ] + $product ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FREE_PRODUCT, [ 'product_id' => 'twelve' ] + $product ) );
    }

    public function test_required_keys_must_be_present(): void {
        $without_currency = $this->fixed_coupon();
        unset( $without_currency['currency'] );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $without_currency ) );

        $without_amount = $this->fixed_coupon();
        unset( $without_amount['amount'] );
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $without_amount ) );

        $this->assertFalse( Reward::config_is_valid( RewardType::PERCENT_COUPON, [ 'currency' => 'USD' ] ) );
        $this->assertFalse( Reward::config_is_valid( RewardType::FREE_PRODUCT, [ 'qty' => 1 ] ) );
    }

    public function test_unknown_keys_are_rejected_and_dropped(): void {
        $this->assertFalse( Reward::config_is_valid( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'percent' => 10 ] ) ) );
        $this->assertSame( $this->fixed_coupon(), Reward::validate_config( RewardType::FIXED_COUPON, $this->fixed_coupon( [ 'percent' => 10 ] ) ) );
    }

    public function test_a_zero_limit_is_stored_as_no_limit(): void {
        $config = Reward::validate_config(
            RewardType::FLEXIBLE_DISCOUNT,
            [
                'max_points_per_order' => 0,
                'max_total'            => 0,
            ] + Reward::default_config( RewardType::FLEXIBLE_DISCOUNT )
        );

        $this->assertNull( $config['max_points_per_order'] );
        $this->assertNull( $config['max_total'] );
        $this->assertNull( Reward::validate_config( RewardType::PERCENT_COUPON, [ 'max_discount' => 0 ] )['max_discount'] );
        $this->assertSame( 0, Reward::validate_config( RewardType::PERCENT_COUPON, [ 'min_order' => 0 ] )['min_order'] );
    }

    public function test_flexible_discount_default_config_still_passes(): void {
        $this->assertTrue(
            Reward::config_is_valid(
                RewardType::FLEXIBLE_DISCOUNT,
                Reward::default_config( RewardType::FLEXIBLE_DISCOUNT )
            )
        );
    }

    public function test_to_update_row_drops_immutable_columns_and_keeps_formats_paired(): void {
        $reward = new Reward(
            3,
            '$5 off',
            'five-off',
            RewardType::FIXED_COUPON,
            Status::ACTIVE,
            2,
            500,
            $this->fixed_coupon(),
            [],
            null,
            null,
            false,
            1,
            1,
            '2026-09-01 00:00:00',
            '2026-09-02 00:00:00',
            null
        );

        $update = $reward->to_update_row();

        foreach ( [ 'slug', 'type', 'created_at', 'created_by', 'is_builtin' ] as $column ) {
            $this->assertArrayNotHasKey( $column, $update['data'], $column );
        }

        $expected = [
            'name'        => '%s',
            'status'      => '%s',
            'sort_order'  => '%d',
            'cost_points' => '%d',
            'config'      => '%s',
            'conditions'  => '%s',
            'starts_at'   => '%s',
            'ends_at'     => '%s',
            'updated_by'  => '%d',
            'updated_at'  => '%s',
            'archived_at' => '%s',
        ];

        $this->assertSame( array_keys( $expected ), array_keys( $update['data'] ) );
        $this->assertSame( array_values( $expected ), $update['format'] );
    }
}
