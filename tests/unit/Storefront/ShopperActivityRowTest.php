<?php
namespace YayLoyalty\Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Storefront\ShopperActivityRow;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;

/**
 * Test ShopperActivityRow::from_entry().
 *
 * Rows are shopper-safe: no admin note, no actor id. Adjustments show the store name, not a person.
 * Keys are exactly: label, order_id, points, created_at. No extra fields leak.
 */
final class ShopperActivityRowTest extends TestCase {

    public function test_order_earn_row(): void {
        $entry = new LedgerEntry(
            1,
            10,
            100,
            100,
            LedgerType::EARN,
            LedgerSource::ORDER,
            null,
            null,
            null,
            null,
            4207,
            null,
            null,
            '2026-10-01 12:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame(
            [
                'label'      => 'Order earned points',
                'order_id'   => 4207,
                'points'     => 100,
                'created_at' => '2026-10-01 12:00:00',
            ],
            $row
        );

        $this->assertCount( 4, $row, 'Row should have exactly 4 keys' );
    }

    public function test_signup_earn_row(): void {
        $entry = new LedgerEntry(
            2,
            10,
            50,
            150,
            LedgerType::EARN,
            LedgerSource::SIGNUP,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 11:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Account created', $row['label'] );
        $this->assertNull( $row['order_id'] );
        $this->assertSame( 50, $row['points'] );
    }

    public function test_review_earn_row(): void {
        $entry = new LedgerEntry(
            3,
            10,
            25,
            175,
            LedgerType::EARN,
            LedgerSource::REVIEW,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 10:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Review earned points', $row['label'] );
    }

    public function test_unknown_source_earn_row(): void {
        $entry = new LedgerEntry(
            4,
            10,
            30,
            205,
            LedgerType::EARN,
            LedgerSource::BIRTHDAY,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 09:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Points earned', $row['label'] );
    }

    public function test_redeem_row(): void {
        $entry = new LedgerEntry(
            5,
            10,
            -50,
            155,
            LedgerType::REDEEM,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 08:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Points for discount', $row['label'] );
        $this->assertSame( -50, $row['points'] );
    }

    public function test_refund_earn_row(): void {
        $entry = new LedgerEntry(
            6,
            10,
            -100,
            55,
            LedgerType::REFUND_EARN,
            LedgerSource::ORDER,
            null,
            null,
            null,
            null,
            4208,
            null,
            null,
            '2026-10-01 07:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Points removed — order refunded', $row['label'] );
        $this->assertSame( -100, $row['points'] );
    }

    public function test_refund_redeem_row(): void {
        $entry = new LedgerEntry(
            7,
            10,
            50,
            105,
            LedgerType::REFUND_REDEEM,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 06:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Refund — points restored', $row['label'] );
    }

    public function test_expire_row(): void {
        $entry = new LedgerEntry(
            8,
            10,
            -30,
            75,
            LedgerType::EXPIRE,
            LedgerSource::EXPIRY,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 05:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Points expired', $row['label'] );
    }

    public function test_adjust_row_shows_store_name(): void {
        $entry = new LedgerEntry(
            9,
            10,
            100,
            175,
            LedgerType::ADJUST,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 04:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'Acme Corp' );

        $this->assertSame( 'Adjustment by Acme Corp', $row['label'] );
    }

    public function test_correction_row_shows_store_name(): void {
        $entry = new LedgerEntry(
            10,
            10,
            -25,
            150,
            LedgerType::CORRECTION,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 03:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'My Store' );

        $this->assertSame( 'Adjustment by My Store', $row['label'] );
    }

    public function test_order_id_only_when_set(): void {
        $with_order = new LedgerEntry(
            1,
            10,
            100,
            100,
            LedgerType::EARN,
            LedgerSource::ORDER,
            null,
            null,
            null,
            null,
            999,
            null,
            null,
            '2026-10-01 12:00:00'
        );

        $without_order = new LedgerEntry(
            2,
            10,
            50,
            150,
            LedgerType::EARN,
            LedgerSource::SIGNUP,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 11:00:00'
        );

        $row_with    = ShopperActivityRow::from_entry( $with_order, 'Store' );
        $row_without = ShopperActivityRow::from_entry( $without_order, 'Store' );

        $this->assertSame( 999, $row_with['order_id'] );
        $this->assertNull( $row_without['order_id'] );
    }

    public function test_no_admin_note_in_output(): void {
        $entry = new LedgerEntry(
            1,
            10,
            100,
            100,
            LedgerType::ADJUST,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            'Internal: suspicious activity',
            null,
            '2026-10-01 12:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'Store' );

        $this->assertArrayNotHasKey( 'note', $row, 'Admin note should not be in output' );
        $this->assertArrayNotHasKey( 'admin_note', $row, 'Admin note should not be in output' );
    }

    public function test_no_actor_in_output(): void {
        $entry = new LedgerEntry(
            1,
            10,
            100,
            100,
            LedgerType::ADJUST,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            null,
            5,
            '2026-10-01 12:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'Store' );

        $this->assertArrayNotHasKey( 'actor_id', $row, 'Actor id should not be in output' );
        $this->assertArrayNotHasKey( 'actor', $row, 'Actor should not be in output' );
    }

    public function test_no_campaign_id_in_output(): void {
        $entry = new LedgerEntry(
            1,
            10,
            100,
            100,
            LedgerType::EARN,
            LedgerSource::ORDER,
            null,
            null,
            42,
            null,
            null,
            null,
            null,
            '2026-10-01 12:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'Store' );

        $this->assertArrayNotHasKey( 'campaign_id', $row, 'Campaign id should not be in output' );
    }

    public function test_negative_points_preserved(): void {
        $entry = new LedgerEntry(
            1,
            10,
            -150,
            -50,
            LedgerType::REDEEM,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 12:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'Store' );

        $this->assertSame( -150, $row['points'] );
    }

    public function test_zero_points_allowed(): void {
        $entry = new LedgerEntry(
            1,
            10,
            0,
            100,
            LedgerType::ADJUST,
            LedgerSource::MANUAL,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            '2026-10-01 12:00:00'
        );

        $row = ShopperActivityRow::from_entry( $entry, 'Store' );

        $this->assertSame( 0, $row['points'] );
    }
}
