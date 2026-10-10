<?php
namespace YayLoyalty\Tests\Integration\Storefront;

use YayLoyalty\Engine\Storefront\ShopperActivityRow;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;

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
}
