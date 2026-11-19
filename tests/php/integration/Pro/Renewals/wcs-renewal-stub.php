<?php
/**
 * Stand-in for WooCommerce Subscriptions' renewal check, which the tests site doesn't install:
 * an order is a renewal when it carries the meta WooCommerce Subscriptions sets on one.
 */

if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
	/**
	 * @param \WC_Order|int $order
	 */
	function wcs_order_contains_renewal( $order ): bool {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order );

		return $order instanceof \WC_Order && '' !== (string) $order->get_meta( '_subscription_renewal' );
	}
}
