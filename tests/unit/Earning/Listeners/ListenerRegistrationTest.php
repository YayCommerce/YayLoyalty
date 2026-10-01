<?php
namespace YayLoyalty\Tests\Unit\Earning\Listeners;

use PHPUnit\Framework\TestCase;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Listeners\OrderListener;
use YayLoyalty\Engine\Earning\Listeners\OrderReversalListener;
use YayLoyalty\Engine\Earning\Listeners\ReviewListener;
use YayLoyalty\Engine\Earning\Listeners\SignupListener;
use YayLoyalty\Engine\Earning\PendingPoints;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;

/**
 * The DI graph builds, and each listener attaches exactly the hooks its
 * register() docblock promises. What the handlers write is asserted in
 * tests/integration/Earning/.
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

    public function test_order_reversal_listener_registers_refund_and_trash_hooks(): void {
        $this->reversal_listener()->register();

        $this->assert_only_hook_callback( 'woocommerce_order_refunded', OrderReversalListener::class, 'on_order_refunded' );
        $this->assert_only_hook_callback( 'woocommerce_refund_deleted', OrderReversalListener::class, 'on_refund_deleted' );
        $this->assert_only_hook_callback( 'woocommerce_trash_order', OrderReversalListener::class, 'on_order_trashed' );
        $this->assert_only_hook_callback( 'trashed_post', OrderReversalListener::class, 'on_order_trashed' );
    }

    public function test_review_listener_registers_both_comment_hooks(): void {
        ( new ReviewListener( EarningService::build(), new LedgerRepository(), Reversal::build() ) )->register();

        $this->assert_only_hook_callback( 'comment_post', ReviewListener::class, 'on_comment_post' );
        $this->assert_only_hook_callback( 'transition_comment_status', ReviewListener::class, 'on_comment_transition' );
    }

    public function test_signup_listener_registers_user_register_only(): void {
        ( new SignupListener( EarningService::build() ) )->register();

        $this->assert_only_hook_callback( 'user_register', SignupListener::class, 'on_user_register' );
        $this->assertArrayNotHasKey( 'woocommerce_created_customer', $GLOBALS['yayloyalty_test_hooks'] ?? [] );
    }

    private function order_listener(): OrderListener {
        return new OrderListener( EarningService::build(), new PendingPoints( new CustomerRepository() ), new LedgerRepository(), $this->reversal_listener() );
    }

    private function reversal_listener(): OrderReversalListener {
        return new OrderReversalListener( new PendingPoints( new CustomerRepository() ), Reversal::build() );
    }

    /** Exactly one callback on $hook, and it is [ $class, $method ]. */
    private function assert_only_hook_callback( string $hook, string $listener_class, string $method ): void {
        $callbacks = $GLOBALS['yayloyalty_test_hooks'][ $hook ] ?? [];

        $this->assertCount( 1, $callbacks, "expected exactly one callback on {$hook}" );
        $this->assertInstanceOf( $listener_class, $callbacks[0][0] );
        $this->assertSame( $method, $callbacks[0][1] );
    }
}
