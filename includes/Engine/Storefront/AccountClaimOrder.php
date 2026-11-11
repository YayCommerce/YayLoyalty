<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Engine\Earning\Listeners\OrderListener;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Enums\OrderStatus;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The My Points tab's "claim an order" form: a signed-in shopper links a guest order placed with their account email,
 * and it earns as if it had been theirs all along. Assigning the customer changes no status, so OrderListener never
 * sees it: a completed order is awarded here, an open one staged here, and the rest earn on completion as usual.
 */
final class AccountClaimOrder {

    public const NONCE_ACTION = 'yayloyalty_claim_order';

    /** The form's order number field; its presence marks the request as a claim. */
    public const FIELD = 'yayloyalty_claim_order';

    /** Statuses that never earn: a claim would only move someone else's history into the account. */
    private const UNCLAIMABLE = [ 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' ];

    /** Statuses OrderListener stages on; it stages a guest order nothing, so the claim stages it. */
    private const STAGEABLE = [ 'pending', 'on-hold' ];

    private OrderListener $orders;

    private Reversal $reversal;

    /** For the duplicate guard and the points the claim earned. */
    private LedgerRepository $ledger;

    public function __construct( OrderListener $orders, Reversal $reversal, LedgerRepository $ledger ) {
        $this->orders   = $orders;
        $this->reversal = $reversal;
        $this->ledger   = $ledger;
    }

    public function register(): void {
        add_action( 'template_redirect', [ $this, 'maybe_claim' ] );
    }

    /** On `template_redirect`: handles a posted claim, then redirects back to the tab with a notice. */
    public function maybe_claim(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is verified below, once the request is known to be a claim.
        if ( ! isset( $_POST[ self::FIELD ] ) || ! is_user_logged_in() || ! is_wc_endpoint_url( AccountMyPoints::ENDPOINT ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), self::NONCE_ACTION ) ) {
            $result = [
                'type'    => 'error',
                'message' => __( 'This form has expired. Reload the page and try again.', 'yayloyalty' ),
            ];
        } else {
            $result = $this->claim( get_current_user_id(), sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) );
        }

        wc_add_notice( $result['message'], $result['type'] );
        wp_safe_redirect( AccountMyPoints::url() );
        exit;
    }

    /**
     * Links the guest order $input names to $user_id when its billing email is the account's, and awards or stages it.
     * Not found, another customer's order and an email mismatch answer alike, so the form reveals no one's orders.
     *
     * @return array{type: string, message: string} A wc_add_notice() type and its message.
     */
    public function claim( int $user_id, string $input ): array {
        $user = get_userdata( $user_id );

        if ( ! $user || ! ProgramGate::program_enabled() || ! Launcher::viewer_can_earn( $user_id ) ) {
            return self::error( __( 'Your account cannot earn points right now.', 'yayloyalty' ) );
        }

        $order = $this->find_order( $input );

        if ( ! $order instanceof \WC_Order || ( $order->get_customer_id() > 0 && $order->get_customer_id() !== $user_id ) || 0 !== strcasecmp( trim( (string) $order->get_billing_email() ), trim( (string) $user->user_email ) ) ) {
            return self::error( __( 'We could not find a guest order with that number for your email address.', 'yayloyalty' ) );
        }

        if ( $order->get_customer_id() === $user_id || [] !== $this->ledger->earn_rows_for_order( (int) $order->get_id() ) ) {
            return self::error( __( 'This order is already in your account.', 'yayloyalty' ) );
        }

        if ( in_array( $order->get_status(), self::UNCLAIMABLE, true ) ) {
            return self::error( __( 'This order cannot earn points.', 'yayloyalty' ) );
        }

        $order->set_customer_id( $user_id );
        $order->save();
        $order->add_order_note( __( 'Claimed by the customer from My Account → My Points.', 'yayloyalty' ) );

        $order_id = (int) $order->get_id();
        $number   = $order->get_order_number();

        if ( OrderStatus::AWARD !== $order->get_status() ) {
            if ( in_array( $order->get_status(), self::STAGEABLE, true ) ) {
                ListenerGuard::for_order( 'AccountClaimOrder', 'stage', $order_id, fn() => $this->orders->on_stageable( $order ) );
            }

            /* translators: %s: order number */
            return self::success( sprintf( __( 'Order #%s is now in your account. Its points arrive when it is completed.', 'yayloyalty' ), $number ) );
        }

        ListenerGuard::for_order(
            'AccountClaimOrder',
            'award',
            $order_id,
            function () use ( $order ): void {
                $this->orders->on_awardable( $order );
                $this->reversal->settle_earlier_refunds( $order );
            }
        );

        $earned = (int) ( $this->ledger->points_by_orders( [ $order_id ] )[ $order_id ]['net'] ?? 0 );

        if ( $earned < 1 ) {
            /* translators: %s: order number */
            return self::success( sprintf( __( 'Order #%s is now in your account. It did not earn any points.', 'yayloyalty' ), $number ) );
        }

        /* translators: 1: order number, 2: points in the store's format, as "1.250 Points" */
        return self::success( sprintf( __( 'Order #%1$s is now in your account. You earned %2$s.', 'yayloyalty' ), $number, SettingsHelper::points_text( $earned ) ) );
    }

    /** The order the shopper typed, as an order number: WooCommerce's order tracking filter maps it, so sequential-number plugins work. */
    private function find_order( string $input ): ?\WC_Order {
        $number = ltrim( trim( $input ), '#' );

        if ( '' === $number ) {
            return null;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter.
        $order = wc_get_order( absint( apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ) ) );

        // A refund is not a WC_Order, so this also turns away a refund's id.
        return $order instanceof \WC_Order ? $order : null;
    }

    /** @return array{type: string, message: string} */
    private static function error( string $message ): array {
        return [
            'type'    => 'error',
            'message' => $message,
        ];
    }

    /** @return array{type: string, message: string} */
    private static function success( string $message ): array {
        return [
            'type'    => 'success',
            'message' => $message,
        ];
    }
}
