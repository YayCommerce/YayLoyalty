<?php
namespace YayLoyalty\Tests\Integration\Controllers;

use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** `POST /customers/{id}/adjust`: which totals a manual adjustment moves. */
final class AdjustRouteTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->act_as_admin();
    }

    protected function tearDown(): void {
        $this->cleanup_orders();
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function test_adjust_moves_balance_and_lifetime(): void {
        $user_id = $this->create_customer();

        $response = $this->adjust( $user_id, [ 'points' => 25 ] );
        $customer = $this->customer_row( $user_id );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 25, (int) $customer['balance'] );
        $this->assertSame( 25, (int) $customer['lifetime_earned'] );
        $this->assertSame( LedgerType::ADJUST, $this->ledger_rows( $user_id )[0]['type'] );
    }

    public function test_correction_moves_balance_only(): void {
        $user_id = $this->create_customer();
        $this->adjust( $user_id, [ 'points' => 25 ] );

        $response = $this->adjust(
            $user_id,
            [
                'points'          => -10,
                'adjust_lifetime' => false,
            ]
        );
        $customer = $this->customer_row( $user_id );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 15, (int) $customer['balance'] );
        $this->assertSame( 25, (int) $customer['lifetime_earned'] );
        $this->assertSame( LedgerType::CORRECTION, $this->ledger_rows( $user_id )[1]['type'] );
    }

    /** @param array<string, mixed> $body */
    private function adjust( int $user_id, array $body ): \WP_REST_Response {
        return $this->send( 'POST', '/yayloyalty/v1/customers/' . $user_id . '/adjust', $body + [ 'note' => 'Integration test' ] );
    }
}
