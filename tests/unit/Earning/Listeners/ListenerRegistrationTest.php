<?php
namespace YayLoyalty\Tests\Unit\Earning\Listeners;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Listeners\OrderListener;
use YayLoyalty\Engine\Earning\Listeners\ReviewListener;
use YayLoyalty\Engine\Earning\Listeners\SignupListener;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;

/**
 * Step 1's only observable surface: the DI graph builds, and each listener
 * attaches exactly the hooks its register() docblock promises. Nothing about
 * on_stageable/on_awardable/on_cancelled/on_refunded/on_approved/
 * on_unapproved/on_registered — those bodies are still no-ops.
 */
final class ListenerRegistrationTest extends TestCase {

    protected function tearDown(): void {
        $GLOBALS['yayloyalty_test_hooks'] = [];
    }

    public function test_build_constructs_the_whole_earning_graph(): void {
        $this->assertInstanceOf( EarningService::class, EarningService::build() );
    }

    public function test_build_survives_a_gates_filter_that_returns_a_non_array(): void {
        add_filter( 'yayloyalty_earning_gates', static fn() => 'nope' );

        $this->assertInstanceOf( EarningService::class, EarningService::build() );
    }

    public function test_order_listener_registers_both_order_hooks(): void {
        $this->order_listener()->register();

        $this->assert_only_hook_callback( 'woocommerce_order_status_changed', OrderListener::class, 'on_status_changed' );
        $this->assert_only_hook_callback( 'woocommerce_new_order', OrderListener::class, 'on_order_created' );
    }

    public function test_review_listener_registers_both_comment_hooks(): void {
        ( new ReviewListener( EarningService::build(), new LedgerRepository() ) )->register();

        $this->assert_only_hook_callback( 'comment_post', ReviewListener::class, 'on_comment_post' );
        $this->assert_only_hook_callback( 'transition_comment_status', ReviewListener::class, 'on_comment_transition' );
    }

    public function test_signup_listener_registers_user_register_only(): void {
        ( new SignupListener( EarningService::build() ) )->register();

        $this->assert_only_hook_callback( 'user_register', SignupListener::class, 'on_user_register' );
        $this->assertArrayNotHasKey( 'woocommerce_created_customer', $GLOBALS['yayloyalty_test_hooks'] ?? [] );
    }

    private function order_listener(): OrderListener {
        $customers = new CustomerRepository();

        return new OrderListener( EarningService::build(), new PendingPoints( $customers ), new LedgerRepository() );
    }

    /** Exactly one callback on $hook, and it is [ $class, $method ]. */
    private function assert_only_hook_callback( string $hook, string $listener_class, string $method ): void {
        $callbacks = $GLOBALS['yayloyalty_test_hooks'][ $hook ] ?? [];

        $this->assertCount( 1, $callbacks, "expected exactly one callback on {$hook}" );
        $this->assertInstanceOf( $listener_class, $callbacks[0][0] );
        $this->assertSame( $method, $callbacks[0][1] );
    }
}
