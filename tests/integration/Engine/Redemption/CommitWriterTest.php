<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\CommitWriter;
use YayLoyalty\Engine\Redemption\Gate;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\Context;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Integrations\YayCurrency\OrderRateSnapshot;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\CurrencyRate;
use YayLoyalty\Utils\Dates;

/** CommitWriter: the redemption row, the ledger row and the order meta land together, once, or not at all. */
final class CommitWriterTest extends IntegrationTestCase {

    /** reward()'s coupon code: the flexible discount's line on every order here. */
    private const FLEXIBLE_DISCOUNT_CODE = 'yayloyalty-flexdisc';

    /** The seeded flexible discount reward: 100 points per currency unit, minimum 100, step 100. */
    private function reward( array $config = [] ): Reward {
        $now = Dates::now();

        return new Reward( 9, 'Cart discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, Status::ACTIVE, 0, null, array_merge( Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ), $config ), [], null, null, true, null, null, $now, $now, null, self::FLEXIBLE_DISCOUNT_CODE );
    }

    public function test_one_order_writes_the_redemption_row_the_ledger_row_and_the_order_meta(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0, self::FLEXIBLE_DISCOUNT_CODE );

        $outcome = $this->commit_writer()->commit( $order, $this->reward() );

        $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );

        $rows = $this->redemption_rows( 7 );
        $this->assertCount( 1, $rows );
        $this->assertSame( Redemption::STATUS_APPLIED, $rows[0]['status'] );
        $this->assertSame( 500, (int) $rows[0]['points'] );
        $this->assertSame( 5.0, (float) $rows[0]['value'] );
        $this->assertSame( $order->get_id(), (int) $rows[0]['order_id'] );
        $this->assertSame( self::FLEXIBLE_DISCOUNT_CODE, $rows[0]['coupon_code'] );
        $this->assertSame( 0, (int) $rows[0]['restored_points'] );

        $ledger = $this->ledger_rows( 7 )[1];
        $this->assertSame( (int) $rows[0]['id'], (int) $ledger['redemption_id'] );
        $this->assertSame( sprintf( 'redeem:7:order:%d:flexible_discount', $order->get_id() ), $ledger['idempotency_key'] );

        $saved = wc_get_order( $order->get_id() );
        $this->assertSame( 500, (int) $saved->get_meta( CommitWriter::META_POINTS ) );
        $this->assertSame( 5.0, (float) $saved->get_meta( CommitWriter::META_VALUE ) );
    }

    /** Auto max is a cart-time request: the commit debits the line it finds, never the most the balance could take. */
    public function test_an_auto_max_order_debits_exactly_its_line(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0, self::FLEXIBLE_DISCOUNT_CODE );

        $outcome = $this->commit_writer()->commit( $order, $this->reward( [ 'redemption_mode' => Reward::REDEMPTION_MODE_AUTO_MAX ] ) );

        $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_a_second_commit_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0, self::FLEXIBLE_DISCOUNT_CODE );

        $this->commit_writer()->commit( $order, $this->reward() );
        $second = $this->commit_writer()->commit( $order, $this->reward() );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertCount( 1, $this->redemption_rows( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_a_balance_that_dropped_fails_with_nothing_written(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 300 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0, self::FLEXIBLE_DISCOUNT_CODE );

        $outcome = $this->commit_writer()->commit( $order, $this->reward() );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( Clamp::BOUND_BALANCE, $outcome->get_reason() );
        $this->assertSame( [ 300 ], $this->ledger_points( 7 ) );
        $this->assertCount( 0, $this->redemption_rows( 7 ) );
        $this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
    }

    /** At 1 point per base unit and 25,000 per base unit saved on the order, 100 points buy 2,500,000. */
    public function test_a_non_base_currency_redemption_stores_the_base_value_and_the_full_rate(): void {
        // CurrencyRate reads the saved rate only through the YayCurrency integration, which tests don't load.
        $rate = static function ( $rate, $currency, $order ) {
            $saved = $order instanceof \WC_Order ? OrderRateSnapshot::read( $order ) : null;

            return null === $saved ? $rate : (float) $saved;
        };
        add_filter( 'yayloyalty_currency_rate', $rate, 10, 3 );

        try {
            $currency = 'VND' === get_option( 'woocommerce_currency' ) ? 'EUR' : 'VND';
            $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
            $order = $this->order_with_points_discount( 7, 5000000.0, 2500000.0, self::FLEXIBLE_DISCOUNT_CODE );
            $order->set_currency( $currency );
            $order->update_meta_data( OrderRateSnapshot::META_KEY, '25000.0000000000' );
            $order->save();

            $outcome = $this->commit_writer()->commit( $order, $this->reward( [ 'points_per_currency_unit' => 1 ] ) );

            $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
            $rows = $this->redemption_rows( 7 );
            $this->assertSame( 100, (int) $rows[0]['points'] );
            $this->assertSame( $currency, $rows[0]['currency'] );
            $this->assertSame( 2500000.0, (float) $rows[0]['value'] );
            $this->assertSame( (float) wc_format_decimal( 2500000 / 25000, CurrencyRate::base_decimals() ), (float) $rows[0]['base_value'] );
            $this->assertSame( '0.0000400000', $rows[0]['rate_snapshot'] );
        } finally {
            remove_filter( 'yayloyalty_currency_rate', $rate, 10 );
        }
    }

    /**
     * User 7's rewards A ($5 off, 500) and B (15% off capped at 10, 1,500), and an order of 100.00 carrying both lines.
     *
     * @return array{0: \WC_Order, 1: array<string, Reward>}
     */
    private function order_with_a_and_b( float $a_discount = 5.0 ): array {
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b     = $this->pro_reward(
            RewardType::PERCENT_COUPON,
            1500,
            [
                'percent'      => 15,
                'max_discount' => 10,
            ]
        );
        $lines = [
            $a->get_coupon_code() => $a_discount,
            $b->get_coupon_code() => 10.0,
        ];

        return [
            $this->order_with_reward_lines( 7, 100.0, $lines ),
            [
                $a->get_coupon_code() => $a,
                $b->get_coupon_code() => $b,
            ],
        ];
    }

    public function test_two_reward_lines_write_two_rows_and_two_keyed_debits(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        list( $order, $rewards ) = $this->order_with_a_and_b();
        list( $a, $b )           = array_values( $rewards );

        $outcome = $this->commit_writer()->commit_rewards( $order, $rewards );

        $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
        $this->assertSame( [ 9850, -500, -1500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 7850, (int) $this->customer_row( 7 )['balance'] );
        $rows = $this->redemption_rows( 7 );
        $this->assertSame( [ 500, 1500 ], array_map( 'intval', array_column( $rows, 'points' ) ) );
        $this->assertSame( [ 5.0, 10.0 ], array_map( 'floatval', array_column( $rows, 'value' ) ) );
        $this->assertSame( [ 'applied', 'applied' ], array_column( $rows, 'status' ) );
        $keys = array_column( array_slice( $this->ledger_rows( 7 ), 1 ), 'idempotency_key' );
        $this->assertSame( [ sprintf( 'redeem:7:order:%d:reward_%d', $order->get_id(), $a->get_id() ), sprintf( 'redeem:7:order:%d:reward_%d', $order->get_id(), $b->get_id() ) ], $keys );
        $saved = wc_get_order( $order->get_id() );
        $this->assertSame( 2000, (int) $saved->get_meta( CommitWriter::META_POINTS ) );
        $this->assertSame( 15.0, (float) $saved->get_meta( CommitWriter::META_VALUE ) );
    }

    public function test_a_second_reward_commit_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        list( $order, $rewards ) = $this->order_with_a_and_b();

        $this->commit_writer()->commit_rewards( $order, $rewards );
        $second = $this->commit_writer()->commit_rewards( $order, $rewards );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( [ 9850, -500, -1500 ], $this->ledger_points( 7 ) );
        $this->assertCount( 2, $this->redemption_rows( 7 ) );
    }

    public function test_a_balance_short_of_the_summed_cost_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1800 ) );
        list( $order, $rewards ) = $this->order_with_a_and_b();

        $outcome = $this->commit_writer()->commit_rewards( $order, $rewards );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( RedemptionService::REASON_REWARDS_BALANCE, $outcome->get_reason() );
        $this->assertSame( [ 1800 ], $this->ledger_points( 7 ) );
        $this->assertCount( 0, $this->redemption_rows( 7 ) );
        $this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
    }

    public function test_a_partial_value_line_debits_the_full_cost_and_stores_the_line_value(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $code  = $a->get_coupon_code();
        $order = $this->order_with_reward_lines( 7, 100.0, [ $code => 3.0 ] );

        $this->commit_writer()->commit_rewards( $order, [ $code => $a ] );

        $rows = $this->redemption_rows( 7 );
        $this->assertSame( [ 500 ], array_map( 'intval', array_column( $rows, 'points' ) ) );
        $this->assertSame( [ 3.0 ], array_map( 'floatval', array_column( $rows, 'value' ) ) );
        $this->assertSame( [ 9850, -500 ], $this->ledger_points( 7 ) );
    }

    /**
     * A and B as in order_with_a_and_b(), plus our flexible discount's line of 5.00 (500 points), on an order of 100.00.
     *
     * @return array{0: \WC_Order, 1: array<string, Reward>}
     */
    private function order_with_a_b_and_points(): array {
        list( , $rewards ) = $this->order_with_a_and_b();
        list( $a, $b )     = array_keys( $rewards );
        $lines             = [
            $a                           => 5.0,
            $b                           => 10.0,
            self::FLEXIBLE_DISCOUNT_CODE => 5.0,
        ];

        return [ $this->order_with_reward_lines( 7, 100.0, $lines ), $rewards ];
    }

    public function test_a_stacked_order_writes_every_row_in_one_commit(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        list( $order, $rewards ) = $this->order_with_a_b_and_points();
        list( $a, $b )           = array_values( $rewards );

        $outcome = $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );

        $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
        $this->assertSame( [ 9850, -500, -1500, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 7350, (int) $this->customer_row( 7 )['balance'] );
        $rows = $this->redemption_rows( 7 );
        $this->assertSame( [ 500, 1500, 500 ], array_map( 'intval', array_column( $rows, 'points' ) ) );
        $this->assertSame( [ 5.0, 10.0, 5.0 ], array_map( 'floatval', array_column( $rows, 'value' ) ) );
        $this->assertSame( self::FLEXIBLE_DISCOUNT_CODE, $rows[2]['coupon_code'] );
        $keys = array_column( array_slice( $this->ledger_rows( 7 ), 1 ), 'idempotency_key' );
        $this->assertSame(
            [
                sprintf( 'redeem:7:order:%d:reward_%d', $order->get_id(), $a->get_id() ),
                sprintf( 'redeem:7:order:%d:reward_%d', $order->get_id(), $b->get_id() ),
                sprintf( 'redeem:7:order:%d:flexible_discount', $order->get_id() ),
            ],
            $keys
        );
        $saved = wc_get_order( $order->get_id() );
        $this->assertSame( 2500, (int) $saved->get_meta( CommitWriter::META_POINTS ) );
        $this->assertSame( 20.0, (float) $saved->get_meta( CommitWriter::META_VALUE ) );
    }

    public function test_a_second_stacked_commit_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        list( $order, $rewards ) = $this->order_with_a_b_and_points();

        $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );
        $second = $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertCount( 3, $this->redemption_rows( 7 ) );
        $this->assertSame( 7350, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_a_stacked_order_above_the_balance_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 2200 ) );
        list( $order, $rewards ) = $this->order_with_a_b_and_points();

        $outcome = $this->commit_writer()->commit_all( $order, $rewards, $this->reward() );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( [ 2200 ], $this->ledger_points( 7 ) );
        $this->assertCount( 0, $this->redemption_rows( 7 ) );
        $this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
    }

    /** The real commit, with every gate and the reversal, as both adapters call it. */
    private function service(): RedemptionService {
        return RedemptionService::build( new SessionIntent() );
    }

    /**
     * The order as WooCommerce leaves it when a retry reuses it: its coupon lines rebuilt from the cart.
     *
     * @param array<string, float> $lines Coupon code => its discount.
     */
    private function rebuilt_with( \WC_Order $order, array $lines ): \WC_Order {
        $order->remove_order_items( 'coupon' );

        foreach ( $lines as $code => $discount ) {
            $coupon = new \WC_Order_Item_Coupon();
            $coupon->set_code( $code );
            $coupon->set_discount( $discount );
            $order->add_item( $coupon );
        }

        $order->save();

        return wc_get_order( $order->get_id() );
    }

    /** @return array<string, string> Coupon code => the row's status. */
    private function statuses(): array {
        $rows = $this->redemption_rows( 7 );

        return array_combine( array_column( $rows, 'coupon_code' ), array_column( $rows, 'status' ) );
    }

    /** A retry swaps B for C of equal value: A is kept, B comes back, and only C is debited. */
    public function test_a_reused_order_debits_only_its_new_line_and_restores_the_line_it_lost(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        list( $order, $rewards ) = $this->order_with_a_and_b();
        list( $a, $b )           = array_values( $rewards );
        $c                       = $this->pro_reward( RewardType::FIXED_COUPON, 700, [ 'amount' => 10 ] );

        $this->assertSame( Outcome::REDEEMED, $this->service()->commit( $order )->get_status() );

        $order = $this->rebuilt_with(
            $order,
            [
                $a->get_coupon_code() => 5.0,
                $c->get_coupon_code() => 10.0,
            ]
        );

        $this->assertSame( Outcome::REDEEMED, $this->service()->commit( $order )->get_status() );
        $this->assertSame( [ 9850, -500, -1500, 1500, -700 ], $this->ledger_points( 7 ) );
        $this->assertSame( 8650, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame(
            [
                $a->get_coupon_code() => Redemption::STATUS_APPLIED,
                $b->get_coupon_code() => Redemption::STATUS_REMOVED,
                $c->get_coupon_code() => Redemption::STATUS_APPLIED,
            ],
            $this->statuses()
        );

        $saved = wc_get_order( $order->get_id() );
        $this->assertSame( 1200, (int) $saved->get_meta( CommitWriter::META_POINTS ) );
        $this->assertSame( 15.0, (float) $saved->get_meta( CommitWriter::META_VALUE ) );
    }

    public function test_a_reused_order_that_swaps_its_only_reward_restores_it_and_debits_the_new_one(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b     = $this->pro_reward( RewardType::FIXED_COUPON, 600, [ 'amount' => 5 ] );
        $order = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0 ] );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with( $order, [ $b->get_coupon_code() => 5.0 ] );

        $this->assertSame( Outcome::REDEEMED, $this->service()->commit( $order )->get_status() );
        $this->assertSame( [ 1000, -500, 500, -600 ], $this->ledger_points( 7 ) );
        $this->assertSame( 400, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( 600, (int) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
    }

    /** The points the lost line gives back pay for the new one: 500 cannot buy both. */
    public function test_a_reused_order_counts_the_restored_points_toward_the_new_line(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 500 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b     = $this->pro_reward( RewardType::PERCENT_COUPON, 500, [ 'percent' => 5 ] );
        $order = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0 ] );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with( $order, [ $b->get_coupon_code() => 5.0 ] );

        $this->assertSame( Outcome::REDEEMED, $this->service()->commit( $order )->get_status() );
        $this->assertSame( 0, (int) $this->customer_row( 7 )['balance'] );
    }

    /** A swapped for a merchant coupon of equal value: no loyalty line is left, so the commit has nothing to debit. */
    public function test_a_reused_order_with_no_loyalty_line_left_restores_every_row(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $order = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0 ] );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with( $order, [ 'merchant-five' => 5.0 ] );

        $outcome = $this->service()->commit( $order );

        $this->assertSame( RedemptionService::REASON_NO_INTENT, $outcome->get_reason() );
        $this->assertNull( RedemptionService::commit_error( $outcome ) );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->statuses()[ $a->get_coupon_code() ] );

        $saved = wc_get_order( $order->get_id() );
        $this->assertSame( '', (string) $saved->get_meta( CommitWriter::META_POINTS ) );
        $this->assertSame( '', (string) $saved->get_meta( CommitWriter::META_VALUE ) );
    }

    /** A, then B, then A again: A's key is spent, so nothing can pay for it and the checkout must stop with no line left. */
    public function test_a_line_whose_row_was_removed_fails_the_checkout_and_leaves_no_discount(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b     = $this->pro_reward( RewardType::FIXED_COUPON, 600, [ 'amount' => 5 ] );
        $order = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0 ] );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with( $order, [ $b->get_coupon_code() => 5.0 ] );
        $this->service()->commit( $order );
        $order = $this->rebuilt_with( $order, [ $a->get_coupon_code() => 5.0 ] );

        $outcome = $this->service()->commit( $order );

        $this->assertSame( RedemptionService::REASON_REWARD_UNAVAILABLE, RedemptionService::commit_error( $outcome ) );
        $this->assertSame( [ 1000, -500, 500, -600, 600 ], $this->ledger_points( 7 ) );
        $this->assertSame( 1000, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( [], wc_get_order( $order->get_id() )->get_coupon_codes() );
    }

    /** A kept line must not stay paid for once a failed commit takes it off the order. */
    public function test_a_failed_commit_on_a_reused_order_restores_the_kept_line_it_takes_off(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 600 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b     = $this->pro_reward( RewardType::FIXED_COUPON, 700, [ 'amount' => 5 ] );
        $order = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0 ] );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with(
            $order,
            [
                $a->get_coupon_code() => 5.0,
                $b->get_coupon_code() => 5.0,
            ]
        );

        $this->assertNotNull( RedemptionService::commit_error( $this->service()->commit( $order ) ) );
        $this->assertSame( [ 600, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 600, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( [], wc_get_order( $order->get_id() )->get_coupon_codes() );
    }

    public function test_a_reused_order_that_did_not_change_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 9850 ) );
        list( $order, $rewards ) = $this->order_with_a_and_b();
        list( $a, $b )           = array_keys( $rewards );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with(
            $order,
            [
                $a => 5.0,
                $b => 10.0,
            ]
        );

        $this->assertSame( Outcome::DUPLICATE, $this->service()->commit( $order )->get_status() );
        $this->assertSame( [ 9850, -500, -1500 ], $this->ledger_points( 7 ) );
    }

    /** The flexible discount is kept and paid once; the swap moves only the reward lines. */
    public function test_a_reused_stacked_order_keeps_the_flexible_discount_and_swaps_the_reward(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 2000 ) );
        $flexible = $this->seeded_reward();
        $a        = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b        = $this->pro_reward( RewardType::FIXED_COUPON, 700, [ 'amount' => 5 ] );
        $order    = $this->order_with_reward_lines(
            7,
            100.0,
            [
                $a->get_coupon_code()        => 5.0,
                $flexible->get_coupon_code() => 5.0,
            ]
        );

        $this->assertSame( Outcome::REDEEMED, $this->service()->commit( $order )->get_status() );

        $order = $this->rebuilt_with(
            $order,
            [
                $b->get_coupon_code()        => 5.0,
                $flexible->get_coupon_code() => 5.0,
            ]
        );

        $this->assertSame( Outcome::REDEEMED, $this->service()->commit( $order )->get_status() );
        $this->assertSame( [ 2000, -500, -500, 500, -700 ], $this->ledger_points( 7 ) );
        $this->assertSame( 800, (int) $this->customer_row( 7 )['balance'] );
        $this->assertSame( 1200, (int) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
    }

    /** The flexible discount's key is spent, so a line worth more than its row could never be paid for. */
    public function test_a_flexible_line_worth_other_than_its_row_fails_the_checkout(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $flexible = $this->seeded_reward();
        $order    = $this->order_with_reward_lines( 7, 100.0, [ $flexible->get_coupon_code() => 5.0 ] );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with( $order, [ $flexible->get_coupon_code() => 8.0 ] );

        $outcome = $this->service()->commit( $order );

        $this->assertSame( RedemptionService::REASON_REWARD_UNAVAILABLE, RedemptionService::commit_error( $outcome ) );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( [], wc_get_order( $order->get_id() )->get_coupon_codes() );
    }

    /** The real commit with a gate that throws, as a failed write would: an exception from inside the commit. */
    private function failing_service(): RedemptionService {
        $gate = new class() implements Gate {
            public function check( Reward $reward, Context $ctx ): ?string {
                throw new \LogicException( 'the commit failed' );
            }
        };
        $add  = static function ( array $gates ) use ( $gate ): array {
            $gates[] = $gate;

            return $gates;
        };

        add_filter( 'yayloyalty_redemption_gates', $add );
        $service = $this->service();
        remove_filter( 'yayloyalty_redemption_gates', $add );

        return $service;
    }

    /** The order must not stay payable with a discount and no debit, and the adapter must still see the failure. */
    public function test_a_commit_that_throws_takes_its_lines_off(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $order = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0 ] );

        try {
            $this->failing_service()->commit( $order );
            $this->fail( 'The exception must reach the adapter.' );
        } catch ( \LogicException $e ) {
            $this->assertSame( 'the commit failed', $e->getMessage() );
        }

        $this->assertSame( [], wc_get_order( $order->get_id() )->get_coupon_codes() );
        $this->assertSame( [ 1000 ], $this->ledger_points( 7 ) );
        $this->assertCount( 0, $this->redemption_rows( 7 ) );
    }

    public function test_a_commit_that_throws_on_a_reused_order_restores_its_row(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $a     = $this->pro_reward( RewardType::FIXED_COUPON, 500, [ 'amount' => 5 ] );
        $b     = $this->pro_reward( RewardType::FIXED_COUPON, 300, [ 'amount' => 5 ] );
        $order = $this->order_with_reward_lines( 7, 100.0, [ $a->get_coupon_code() => 5.0 ] );

        $this->service()->commit( $order );
        $order = $this->rebuilt_with(
            $order,
            [
                $a->get_coupon_code() => 5.0,
                $b->get_coupon_code() => 5.0,
            ]
        );

        try {
            $this->failing_service()->commit( $order );
            $this->fail( 'The exception must reach the adapter.' );
        } catch ( \LogicException $e ) {
            $this->assertSame( 'the commit failed', $e->getMessage() );
        }

        $this->assertSame( [], wc_get_order( $order->get_id() )->get_coupon_codes() );
        $this->assertSame( [ 1000, -500, 500 ], $this->ledger_points( 7 ) );
        $this->assertSame( Redemption::STATUS_REMOVED, $this->statuses()[ $a->get_coupon_code() ] );
    }
}
