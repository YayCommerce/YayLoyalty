<?php
namespace YayLoyalty\Tests\Integration\Engine\Redemption;

use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Redemption\CommitWriter;
use YayLoyalty\Engine\Redemption\Quoter;
use YayLoyalty\Engine\Rewards\Clamp;
use YayLoyalty\Engine\Rewards\ContextFactory;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Engine\Rewards\RewardEngine;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Redemption;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RedemptionRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;

/** CommitWriter: the redemption row, the ledger row and the order meta land together, once, or not at all. */
final class CommitWriterTest extends IntegrationTestCase {

    private function writer(): CommitWriter {
        $customers = new CustomerRepository();

        return new CommitWriter(
            $this->ledger_service(),
            new RedemptionRepository(),
            $customers,
            new ContextFactory( $customers ),
            new Quoter( new RewardEngine( Registry::with_lite_handlers() ), [] )
        );
    }

    /** The seeded slider reward: 100 points per currency unit, minimum 100, step 100. */
    private function reward(): Reward {
        $now = Dates::now();

        return new Reward( 9, 'Cart discount', 'cart-discount', RewardType::FLEXIBLE_DISCOUNT, Status::ACTIVE, 0, null, Reward::default_config( RewardType::FLEXIBLE_DISCOUNT ), [], null, null, true, null, null, $now, $now );
    }

    /** An order with one line and our coupon line, as checkout leaves it. */
    private function order_with_points_discount( int $user_id, float $subtotal, float $discount ): \WC_Order {
        $order = wc_create_order( [ 'customer_id' => $user_id ] );

        $line = new \WC_Order_Item_Product();
        $line->set_name( 'Thing' );
        $line->set_quantity( 1 );
        $line->set_subtotal( $subtotal );
        $line->set_total( $subtotal - $discount );
        $order->add_item( $line );

        $coupon = new \WC_Order_Item_Coupon();
        $coupon->set_code( 'yayloyalty-redeem' );
        $coupon->set_discount( $discount );
        $order->add_item( $coupon );

        $order->save();

        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    private function redemption_rows( int $user_id ): array {
        global $wpdb;

        return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
            $wpdb->prepare( 'SELECT * FROM `' . esc_sql( ( new RedemptionRepository() )->table() ) . '` WHERE user_id = %d ORDER BY id ASC', $user_id ),
            ARRAY_A
        );
    }

    public function test_one_order_writes_the_redemption_row_the_ledger_row_and_the_order_meta(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0 );

        $outcome = $this->writer()->commit( $order, $this->reward() );

        $this->assertSame( Outcome::REDEEMED, $outcome->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );

        $rows = $this->redemption_rows( 7 );
        $this->assertCount( 1, $rows );
        $this->assertSame( Redemption::STATUS_APPLIED, $rows[0]['status'] );
        $this->assertSame( 500, (int) $rows[0]['points'] );
        $this->assertSame( 5.0, (float) $rows[0]['value'] );
        $this->assertSame( $order->get_id(), (int) $rows[0]['order_id'] );
        $this->assertSame( 'yayloyalty-redeem', $rows[0]['coupon_code'] );
        $this->assertSame( 0, (int) $rows[0]['restored_points'] );

        $ledger = $this->ledger_rows( 7 )[1];
        $this->assertSame( (int) $rows[0]['id'], (int) $ledger['redemption_id'] );
        $this->assertSame( sprintf( 'redeem:7:order:%d:flexible_discount', $order->get_id() ), $ledger['idempotency_key'] );

        $saved = wc_get_order( $order->get_id() );
        $this->assertSame( 500, (int) $saved->get_meta( CommitWriter::META_POINTS ) );
        $this->assertSame( 5.0, (float) $saved->get_meta( CommitWriter::META_VALUE ) );
    }

    public function test_a_second_commit_writes_nothing(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 1000 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0 );

        $this->writer()->commit( $order, $this->reward() );
        $second = $this->writer()->commit( $order, $this->reward() );

        $this->assertSame( Outcome::DUPLICATE, $second->get_status() );
        $this->assertSame( [ 1000, -500 ], $this->ledger_points( 7 ) );
        $this->assertCount( 1, $this->redemption_rows( 7 ) );
        $this->assertSame( 500, (int) $this->customer_row( 7 )['balance'] );
    }

    public function test_a_balance_that_dropped_fails_with_nothing_written(): void {
        $this->ledger_service()->earn( $this->earn_draft( 7, 100, 300 ) );
        $order = $this->order_with_points_discount( 7, 20.0, 5.0 );

        $outcome = $this->writer()->commit( $order, $this->reward() );

        $this->assertSame( Outcome::SKIPPED, $outcome->get_status() );
        $this->assertSame( Clamp::BOUND_BALANCE, $outcome->get_reason() );
        $this->assertSame( [ 300 ], $this->ledger_points( 7 ) );
        $this->assertCount( 0, $this->redemption_rows( 7 ) );
        $this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( CommitWriter::META_POINTS ) );
    }
}
