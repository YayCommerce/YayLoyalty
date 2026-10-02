<?php
/**
 * Points earned email (plain text).
 *
 * Override by copying to yourtheme/woocommerce/emails/plain/yayloyalty-points-earned.php.
 *
 * @var string $email_heading
 * @var string $additional_content
 * @var string $greeting_name The customer's first name, else display name; '' for none. Absent when there is no award to show.
 * @var string $earned_text   Raw text, e.g. "You earned 50 Points for order #123." Absent when there is no award to show.
 * @var string $balance_text  The balance after this award, e.g. "120 Points". Absent when there is no award to show.
 * @var string $balance_value What the balance is worth, e.g. "$1.50"; '' when the discount reward is not live. Absent when there is no award to show.
 * @var string $cta_url      Where "View your points" links. Absent when there is no award to show.
 * @var \YayLoyalty\Models\LedgerEntry $entry The awarded row, for overrides. Absent when there is no award to show.
 */

defined( 'ABSPATH' ) || exit;

// Every line goes through wptexturize() before esc_html(): WooCommerce's plain-text cleanup deletes &#039; but maps typographic &#8217; back to an apostrophe.

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own email hooks: firing them is what gives this email the store's header, footer and styles.

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

if ( ! empty( $earned_text ) ) {
    if ( ! empty( $greeting_name ) ) {
        /* translators: %s: customer's first name. */
        echo esc_html( wptexturize( sprintf( __( 'Hi %s,', 'yayloyalty' ), $greeting_name ) ) ) . "\n\n";
    }

    echo esc_html( wptexturize( $earned_text ) ) . "\n\n";
    /* translators: %s: points with label, e.g. "120 Points". */
    echo esc_html( wptexturize( sprintf( __( 'Your balance is now %s.', 'yayloyalty' ), $balance_text ) ) ) . "\n\n";

    if ( ! empty( $balance_value ) ) {
        /* translators: %s: money amount, e.g. "$1.50". */
        echo esc_html( wptexturize( sprintf( __( 'That\'s worth %s in rewards.', 'yayloyalty' ), $balance_value ) ) ) . "\n\n";
    }
} else {
    echo esc_html__( 'Thanks for being part of our loyalty program. New points have been added to your account.', 'yayloyalty' ) . "\n\n";
}

if ( ! empty( $cta_url ) ) {
    echo esc_html__( 'View your points:', 'yayloyalty' ) . ' ' . esc_url_raw( $cta_url ) . "\n\n";
}

if ( $additional_content ) {
    echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
