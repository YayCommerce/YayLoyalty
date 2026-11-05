<?php
namespace YayLoyalty\Tests\Integration\Storefront;

use YayLoyalty\Engine\Redemption\Adapters\ClassicCheckoutAdapter;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Storefront\RedeemCard;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Tests\Integration\IntegrationTestCase;
use YayLoyalty\Tests\Integration\WooCommerceOrdersFixture;

/** The guest sign-in card says what the cart earns on classic pages, and keeps its plain line when there is nothing to promise. */
final class GuestCartEarnLineTest extends IntegrationTestCase {

    use WooCommerceOrdersFixture;

    private const PLAIN_LINE = 'Sign in to spend your';

    protected function setUp(): void {
        parent::setUp();

        $this->require_woocommerce();
        wp_set_current_user( 0 );
    }

    protected function tearDown(): void {
        WC()->cart->set_cart_contents( [] );
        $this->cleanup_orders();

        parent::tearDown();
    }

    public function test_the_classic_totals_row_shows_what_the_cart_earns(): void {
        $this->pin_settings();
        $this->create_campaign();
        $this->fill_cart( 2 );

        $row = $this->classic_row();

        $this->assertStringContainsString( 'yayloyalty-redeem-row', $row );
        $this->assertStringContainsString( 'Sign in to earn <strong>20 points</strong> with this order.', $row );
        $this->assertStringNotContainsString( self::PLAIN_LINE, $row );
    }

    public function test_a_cart_that_earns_nothing_keeps_the_plain_line(): void {
        $this->pin_settings();
        $this->fill_cart( 2 );

        $row = $this->classic_row();

        $this->assertStringContainsString( self::PLAIN_LINE, $row );
        $this->assertStringNotContainsString( 'Sign in to earn', $row );
    }

    public function test_a_paused_program_keeps_the_plain_line(): void {
        $this->pin_settings( [ 'enabled' => false ] );
        $this->create_campaign();
        $this->fill_cart( 2 );

        $row = $this->classic_row();

        $this->assertStringContainsString( self::PLAIN_LINE, $row );
        $this->assertStringNotContainsString( 'Sign in to earn', $row );
    }

    /** $quantity of a 10-unit product, added the way a shopper adds it. */
    private function fill_cart( int $quantity ): void {
        wc_load_cart();
        WC()->cart->set_cart_contents( [] );
        WC()->cart->add_to_cart( $this->create_product(), $quantity );
        WC()->cart->calculate_totals();
    }

    private function classic_row(): string {
        $intent  = new SessionIntent();
        $service = RedemptionService::build( $intent );
        $adapter = new ClassicCheckoutAdapter( $service, new RedeemCard( $service, $intent, new CustomerRepository() ) );

        ob_start();
        $adapter->render_row();

        return (string) ob_get_clean();
    }
}
