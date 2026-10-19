<?php
namespace YayLoyalty\Tests\Integration\Storefront;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** The storefront promises a discount only to a shopper checkout would let redeem. */
final class LauncherPayloadRedeemGateTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        $this->seeded_reward();
        // The redemption gate refuses everyone while coupons are off; the site's own option must not decide the result.
        add_filter( 'pre_option_woocommerce_enable_coupons', [ $this, 'coupons_on' ] );
    }

    protected function tearDown(): void {
        remove_filter( 'pre_option_woocommerce_enable_coupons', [ $this, 'coupons_on' ] );
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function coupons_on(): string {
        return 'yes';
    }

    public function test_member_who_may_redeem_is_shown_the_discount(): void {
        $this->pin_settings();
        $payload = $this->payload_for( $this->customer_with_points() );

        $this->assertNotNull( $payload['redeem'] );
        $this->assertSame( 500, $payload['member']['balance'] );
        $this->assertSame( 5.0, $payload['member']['worth'] );
    }

    public function test_excluded_role_is_promised_no_discount(): void {
        $this->pin_settings( [ 'excluded_roles' => [ 'customer' ] ] );
        $payload = $this->payload_for( $this->customer_with_points() );

        $this->assertNull( $payload['redeem'] );
        $this->assertSame( 500, $payload['member']['balance'] );
        $this->assertNull( $payload['member']['worth'] );
    }

    public function test_opted_out_member_is_promised_no_discount(): void {
        $this->pin_settings();
        $user_id = $this->customer_with_points();
        update_user_meta( $user_id, ProgramGate::META_OPTED_OUT, 1 );

        $payload = $this->payload_for( $user_id );

        $this->assertNull( $payload['redeem'] );
        $this->assertSame( 500, $payload['member']['balance'] );
        $this->assertNull( $payload['member']['worth'] );
    }

    /** A customer holding 500 points, worth 5 at the seeded reward's 100 points per unit. */
    private function customer_with_points(): int {
        $user_id = $this->create_customer();
        $this->ledger_service()->adjust( $user_id, 500, 'test balance', 1 );

        return $user_id;
    }

    private function payload_for( int $user_id ): array {
        return LauncherPayload::for_user( DesignHelper::get_design(), $user_id );
    }
}
