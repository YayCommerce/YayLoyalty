<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\CommitWriter;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Rewards\Clamp;
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
}
