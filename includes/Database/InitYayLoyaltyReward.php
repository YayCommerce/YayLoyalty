<?php
namespace YayLoyalty\Database;

use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * One-time demo catalog: four Pro reward types alongside the seeded
 * flexible-discount built-in, so the Rewards screen has real rows to render
 * before the reward editor can create any.
 *
 * TEMP-DEMO-SEED: remove once reward CRUD exists.
 *
 * Runs exactly once: the option flag is claimed with add_option() BEFORE any
 * write (the opposite of the version stamp), because a retry after a
 * mid-loop crash would create a second batch of rows.
 */
final class InitYayLoyaltyReward {

    public const OPTION_INITIALIZED = 'yayloyalty_demo_rewards_initialized';

    public static function maybe_create(): void {
        // Checked before claiming the flag, same as InitYayLoyaltyCustomer's wc_create_new_customer
        // guard: Installer::install() can run before WooCommerce loads (plugins_loaded, outside its
        // guard), and claiming here regardless would permanently skip the free-product row once WC
        // never gets checked again.
        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        /**
         * Whether to create the demo catalog rewards on install.
         *
         * @param bool $create
         */
        if ( ! apply_filters( 'yayloyalty_init_demo_rewards', true ) ) {
            return;
        }

        if ( ! add_option( self::OPTION_INITIALIZED, Dates::now(), '', 'no' ) ) {
            return; // Already claimed — by this request or an earlier one.
        }

        $rewards = new RewardRepository();

        // array_filter drops the null free-product row on a store with no published product;
        // passing it to create_one()'s array param would throw a TypeError past its try/catch.
        foreach ( array_filter( self::reward_data() ) as $data ) {
            self::create_one( $rewards, $data );
        }
    }

    /**
     * One failed row logs and the loop moves on: the flag is already
     * claimed, so an escaping exception would skip every later row forever.
     */
    private static function create_one( RewardRepository $rewards, array $data ): void {
        try {
            $now = Dates::now();

            $rewards->insert(
                new Reward(
                    null,
                    $data['name'],
                    $data['slug'],
                    $data['type'],
                    $data['status'],
                    $data['sort_order'],
                    $data['cost_points'],
                    $data['config'],
                    $data['conditions'],
                    null,
                    null,
                    false,
                    null,
                    null,
                    $now,
                    $now
                )
            );
        } catch ( \Throwable $exception ) {
            Log::error( 'InitYayLoyaltyReward', 'failed to write reward ' . $data['slug'] . ': ' . $exception->getMessage() );
        }
    }

    /**
     * Four catalog rewards, one per Pro type, addressed by a demo- prefixed
     * slug so they are easy to find and remove. The free-product row is
     * skipped when the store has no published product to reference.
     *
     * @return array<int, array<string, mixed>|null>
     */
    private static function reward_data(): array {
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

        return [
            [
                'name'        => __( '$5 off', 'yayloyalty' ),
                'slug'        => 'demo-5-off',
                'type'        => RewardType::FIXED_COUPON,
                'status'      => Status::ACTIVE,
                'sort_order'  => 1,
                'cost_points' => 500,
                'config'      => [
                    'currency'           => $currency,
                    'amount'             => 5,
                    'min_order'          => 25,
                    'coupon_expiry_days' => 60,
                    'exclude_sale_items' => false,
                    'max_total'          => null,
                ],
                'conditions'  => [],
            ],
            [
                'name'        => __( 'Free shipping', 'yayloyalty' ),
                'slug'        => 'demo-free-shipping',
                'type'        => RewardType::FREE_SHIPPING,
                'status'      => Status::ACTIVE,
                'sort_order'  => 2,
                'cost_points' => 800,
                'config'      => [
                    'min_order'          => 0,
                    'currency'           => $currency,
                    'coupon_expiry_days' => 60,
                    'max_total'          => null,
                ],
                'conditions'  => [],
            ],
            [
                'name'        => __( '15% off', 'yayloyalty' ),
                'slug'        => 'demo-15-off',
                'type'        => RewardType::PERCENT_COUPON,
                'status'      => Status::ACTIVE,
                'sort_order'  => 3,
                'cost_points' => 1500,
                'config'      => [
                    'percent'            => 15,
                    'max_discount'       => null,
                    'currency'           => $currency,
                    'min_order'          => 0,
                    'coupon_expiry_days' => 30,
                    'exclude_sale_items' => false,
                    'max_total'          => null,
                ],
                // Stands in for the wireframe's "Summit tier only": tier is not offered yet (see
                // docs/data-model/rewards.md, RewardConditionType::UNAVAILABLE).
                'conditions'  => [
                    [
                        'type'     => 'redemption_count',
                        'operator' => 'lt',
                        'value'    => 1,
                    ],
                    [
                        'type'     => 'lifetime_points',
                        'operator' => 'gte',
                        'value'    => 5000,
                    ],
                ],
            ],
            self::free_product_row(),
        ];
    }

    /**
     * Null when the store has no published product — the row is skipped, never faked.
     * wc_get_products() is already guarded to exist by maybe_create() before this runs.
     */
    private static function free_product_row(): ?array {
        $ids = wc_get_products(
            [
                'limit'   => 1,
                'status'  => 'publish',
                'orderby' => 'title',
                'order'   => 'ASC',
                'return'  => 'ids',
            ]
        );

        $product_id = $ids[0] ?? null;

        if ( null === $product_id ) {
            return null;
        }

        $product = wc_get_product( $product_id );

        return [
            'name'        => $product ? $product->get_name() : __( 'Free product', 'yayloyalty' ),
            'slug'        => 'demo-free-product',
            'type'        => RewardType::FREE_PRODUCT,
            // Paused, matching the wireframe row this seeds towards (1b).
            'status'      => Status::PAUSED,
            'sort_order'  => 4,
            'cost_points' => 3000,
            'config'      => [
                'product_id'         => (int) $product_id,
                'variation_id'       => null,
                'qty'                => 1,
                'coupon_expiry_days' => 60,
                'max_total'          => 100,
            ],
            'conditions'  => [],
        ];
    }
}
