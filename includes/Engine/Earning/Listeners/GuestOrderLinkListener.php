<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use Automattic\WooCommerce\Internal\CustomerEmailVerification\EmailVerificationService;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Awards a completed guest order once WooCommerce links it to an account. Linking changes no status, so OrderListener
 * never sees it. WooCommerce links only when the email is proven (verification, a password reset) or an admin asks,
 * never on registration: a matching email alone would let anyone claim a stranger's points.
 */
final class GuestOrderLinkListener {

    private OrderListener $orders;

    private OrderReversalListener $reversals;

    /** For the missing-points check. */
    private LedgerRepository $ledger;

    public function __construct( OrderListener $orders, OrderReversalListener $reversals, LedgerRepository $ledger ) {
        $this->orders    = $orders;
        $this->reversals = $reversals;
        $this->ledger    = $ledger;
    }

    public function register(): void {
        add_action( 'woocommerce_update_new_customer_past_order', [ $this, 'on_order_linked' ], 10, 1 );
        add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
    }

    /**
     * Awards the order as if it had just completed. is_missing_points() is the duplicate guard: the earn key holds
     * the user id, so an order that earned under another customer would compose a new key. An order not completed yet is skipped, and awards on completion.
     */
    public function on_order_linked( int $order_id ): void {
        $order = wc_get_order( $order_id );

        if ( ! $order instanceof \WC_Order || ! EarningService::is_missing_points( $order, $this->ledger ) ) {
            return;
        }

        ListenerGuard::for_order(
            'GuestOrderLinkListener',
            'award',
            $order_id,
            function () use ( $order ): void {
                $this->orders->on_awardable( $order );
                $this->reversals->settle_earlier_refunds( $order );
            }
        );
    }

    /**
     * WooCommerce links a customer's guest orders once, on verifying: a guest order placed after that stays unlinked,
     * so a verified customer's login links it. The fired hook then awards it. No-op before WooCommerce 11.0.
     */
    public function on_login( string $user_login, \WP_User $user ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the action's signature.
        ListenerGuard::run(
            'GuestOrderLinkListener',
            'order link failed for user #' . $user->ID,
            function () use ( $user ): void {
                if ( ! ProgramGate::program_enabled() || ! class_exists( EmailVerificationService::class ) ) {
                    return;
                }

                if ( wc_get_container()->get( EmailVerificationService::class )->is_verified( (int) $user->ID ) ) {
                    wc_update_new_customer_past_orders( (int) $user->ID );
                }
            }
        );
    }
}
