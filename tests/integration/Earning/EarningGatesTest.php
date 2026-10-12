<?php
namespace YayLoyalty\Tests\Integration\Earning;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;
use YayLoyalty\Utils\Table;

/** ProgramGate's rules stopping a completed order from earning. */
final class EarningGatesTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->create_campaign();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_disabled_program_awards_nothing(): void {
        $this->pin_settings( [ 'enabled' => false ] );
        $user_id = $this->create_customer();

        $order = $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );

        $this->assertSame( [], $this->ledger_points( $user_id ) );
        $this->assertContains( 'No points earned: ' . ProgramGate::REASON_PROGRAM_PAUSED . '.', $this->order_notes( $order ) );
    }

    public function test_opted_out_customer_awards_nothing(): void {
        $this->pin_settings();
        $user_id = $this->create_customer();
        update_user_meta( $user_id, ProgramGate::META_OPTED_OUT, 1 );

        $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );

        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    public function test_excluded_role_awards_nothing(): void {
        $this->pin_settings( [ 'excluded_roles' => [ 'customer' ] ] );
        $user_id = $this->create_customer();

        $this->transition( $this->create_order( $user_id, [ 40 ] ), 'completed' );

        $this->assertSame( [], $this->ledger_points( $user_id ) );
    }

    public function test_guest_order_awards_nothing(): void {
        $this->pin_settings();

        $this->transition( $this->create_order( null, [ 40 ] ), 'completed' );

        $this->assertSame( 0, $this->ledger_row_count() );
    }

    private function ledger_row_count(): int {
        global $wpdb;

        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( Table::name( 'ledger' ) ) . '`' ); // phpcs:ignore WordPress.DB -- tests read the table directly; the table name is escaped.
    }
}
