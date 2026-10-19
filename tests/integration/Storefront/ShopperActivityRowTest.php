<?php
namespace YayLoyalty\Tests\Integration\Storefront;

use YayLoyalty\Engine\Storefront\ShopperActivityRow;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Text;

/** The launcher's activity rows are shopper-facing, so nothing staff-only may reach them. */
final class ShopperActivityRowTest extends IntegrationTestCase {

    public function test_an_admin_note_never_reaches_the_shopper(): void {
        $user_id = 424242;
        $note    = 'Internal: goodwill after fraud check';

        $this->ledger_service()->adjust( $user_id, 50, $note, 1 );

        $items = ShopperActivityRow::items( ( new LedgerRepository() )->latest_for_user( $user_id, 10 ) );

        $this->assertCount( 1, $items );
        $this->assertSame( [ 'label', 'meta', 'delta' ], array_keys( $items[0] ) );
        $this->assertStringNotContainsString( $note, (string) wp_json_encode( $items ) );
    }

    public function test_adjustment_with_customer_note_is_labelled_with_it(): void {
        $entry = $this->ledger_service()->adjust( 424242, 50, 'internal reason', 1, true, 'Thanks for the review!' );

        $this->assertSame( 'Thanks for the review!', ( new LedgerRepository() )->find( (int) $entry->get_id() )->get_customer_note() );
        $this->assertSame( 'Thanks for the review!', $this->latest_label( 424242 ) );
    }

    public function test_adjustment_without_customer_note_keeps_store_label(): void {
        $store_label = sprintf( 'Adjustment by %s', Text::store_name() );

        $without = $this->ledger_service()->adjust( 424242, 50, 'internal reason', 1 );
        $this->assertNull( ( new LedgerRepository() )->find( (int) $without->get_id() )->get_customer_note() );
        $this->assertSame( $store_label, $this->latest_label( 424242 ) );

        $blank = $this->ledger_service()->adjust( 424243, 50, 'internal reason', 1, true, '   ' );
        $this->assertNull( ( new LedgerRepository() )->find( (int) $blank->get_id() )->get_customer_note() );
        $this->assertSame( $store_label, $this->latest_label( 424243 ) );
    }

    /** LedgerService::restore_refund() writes ADJUST rows with the order's source; only a manual row shows a customer note. */
    public function test_refund_restore_row_never_shows_customer_note(): void {
        $row = new LedgerEntry( 1, 424242, 50, 50, LedgerType::ADJUST, LedgerSource::ORDER, null, null, null, null, 7, 'Refund #9 deleted', null, Dates::now(), null, 'Should not show' );

        $items = ShopperActivityRow::items( [ $row ] );

        $this->assertSame( sprintf( 'Adjustment by %s', Text::store_name() ), $items[0]['label'] );
    }

    public function test_birthday_award_is_labelled_birthday_bonus(): void {
        $row = new LedgerEntry( 1, 424242, 150, 150, LedgerType::EARN, LedgerSource::BIRTHDAY, null, null, null, null, null, null, null, Dates::now() );

        $this->assertSame( 'Birthday bonus', ShopperActivityRow::items( [ $row ] )[0]['label'] );
        $this->assertSame( 'Birthday bonus', ShopperActivityRow::history_rows( [ $row ] )[0]['label'] );
    }

    private function latest_label( int $user_id ): string {
        return ShopperActivityRow::items( ( new LedgerRepository() )->latest_for_user( $user_id, 1 ) )[0]['label'];
    }
}
