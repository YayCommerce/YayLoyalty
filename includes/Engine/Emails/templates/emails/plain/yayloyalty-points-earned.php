<?php
/**
 * Points earned email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-points-earned.php.
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $earned_text  What was earned and why, e.g. "You earned 50 Points for order #123." Absent in the settings preview.
 * @var string $balance_text The balance after this award, e.g. "120 Points". Absent in the settings preview.
 * @var string $cta_url      Where "View your points" links. Absent in the settings preview.
 * @var \YayLoyalty\Models\LedgerEntry $entry The awarded row. Absent in the settings preview.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks: firing them is what gives this email the store's header, footer and styles.

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $earned_text ) ) {
    echo esc_html( $earned_text ) . "\n\n";
} else {
    echo esc_html__( 'Thanks for being part of our loyalty program. New points have been added to your account.', 'yayloyalty' ) . "\n\n";
}

if ( ! empty( $balance_text ) ) {
    /* translators: %s: points with label, e.g. "120 Points". */
    echo esc_html( sprintf( __( 'Your balance is now %s.', 'yayloyalty' ), $balance_text ) ) . "\n\n";
}

if ( ! empty( $cta_url ) ) {
    echo esc_html__( 'View your points:', 'yayloyalty' ) . ' ' . esc_url_raw( $cta_url ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
